<?php

declare(strict_types=1);

namespace OCA\NCCare\Service;

use OCA\NCCare\Compat\AppAvailability;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Automatic security and compliance checks.
 *
 * Every check is evaluated in isolation: a failing check is reported as
 * "unknown" and never prevents the remaining ones from running.
 *
 * Each check returns:
 *   ok     bool|null  whether the instance meets the recommendation
 *   value  mixed      the raw value detected, for display and backend rules
 *   status string     ok | warn | error | unknown
 *
 * @psalm-type CheckResult = array{ok: bool|null, value: mixed, status: string}
 * @psalm-api
 */
class ComplianceCheckService {

	public const string STATUS_OK = 'ok';
	public const string STATUS_WARN = 'warn';
	public const string STATUS_ERROR = 'error';
	public const string STATUS_UNKNOWN = 'unknown';

	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly AppAvailability $apps,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly FailedLoginCounter $failedLogins,
	) {
	}

	/**
	 * Run every check.
	 *
	 * @return array<string, CheckResult>
	 */
	public function getAllChecks(): array {
		$checks = [
			'audit_log' => fn (): array => $this->checkAuditLog(),
			'cron' => fn (): array => $this->checkCron(),
			'cron_jobs_stale' => fn (): array => $this->checkStaleJobs(),
			'notify_push' => fn (): array => $this->checkNotifyPush(),
			'twofa_enforced' => fn (): array => $this->checkTwoFactor(),
			'trashbin' => fn (): array => $this->checkAppEnabled('files_trashbin'),
			'versioning' => fn (): array => $this->checkAppEnabled('files_versions'),
			'activity_app' => fn (): array => $this->checkAppEnabled('activity'),
			// Il limitatore è nel core e resta attivo comunque: l'app aggiunge solo la
			// gestione degli indirizzi, quindi la sua assenza è da rivedere, non grave.
			'brute_force_protection' => fn (): array => $this->checkAppEnabled('bruteforcesettings', self::STATUS_WARN),
			'password_policy' => fn (): array => $this->checkPasswordPolicy(),
			'encryption' => fn (): array => $this->checkEncryption(),
			'brute_force_24h' => fn (): array => $this->checkBruteForceAttempts(),
			'links_no_expiry' => fn (): array => $this->checkLinksWithoutExpiry(),
			'links_no_password' => fn (): array => $this->checkLinksWithoutPassword(),
			'share_password_enforced' => fn (): array => $this->checkSharePasswordEnforced(),
			'memcache_configured' => fn (): array => $this->checkMemcache(),
			'debug_disabled' => fn (): array => $this->checkDebugDisabled(),
			'opcache_enabled' => fn (): array => $this->checkOpcache(),
		];

		$results = [];
		foreach ($checks as $key => $callback) {
			$results[$key] = $this->safely($key, $callback);
		}

		// Office and collaboration checks only make sense when the app is present,
		// otherwise they would report a problem on instances that simply do not
		// use those integrations.
		foreach ($this->getOptionalIntegrationChecks() as $key => $result) {
			$results[$key] = $result;
		}

		return $results;
	}

	/**
	 * @param callable(): CheckResult $callback
	 * @return CheckResult
	 */
	private function safely(string $key, callable $callback): array {
		try {
			return $callback();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'NC Care:check "' . $key . '" failed: ' . $e->getMessage(),
				['app' => 'nccare', 'exception' => $e],
			);
			return $this->result(null, null, self::STATUS_UNKNOWN);
		}
	}

	/** @return CheckResult */
	private function result(?bool $ok, mixed $value, string $status): array {
		return ['ok' => $ok, 'value' => $value, 'status' => $status];
	}

	// ─── Individual checks ──────────────────────────────────────────────────

	/**
	 * The audit log needs both the app enabled and a destination configured,
	 * either a dedicated audit logfile or admin_audit listed in log.condition.
	 *
	 * @return CheckResult
	 */
	private function checkAuditLog(): array {
		if (!$this->apps->isEnabled('admin_audit')) {
			return $this->result(false, 'disabled', self::STATUS_ERROR);
		}

		/** @psalm-suppress MixedAssignment IConfig::getSystemValue() is untyped by the API */
		$condition = $this->config->getSystemValue('log.condition', []);
		/** @psalm-suppress MixedAssignment the value comes from the untyped log.condition setting */
		$apps = is_array($condition) ? ($condition['apps'] ?? []) : [];
		$inCondition = is_array($apps) && in_array('admin_audit', $apps, true);
		$auditFile = $this->config->getSystemValueString('logfile_audit', '');

		if ($inCondition || $auditFile !== '') {
			return $this->result(true, 'configured', self::STATUS_OK);
		}

		return $this->result(false, 'enabled_no_logfile', self::STATUS_WARN);
	}

	/**
	 * System cron is the recommended mode. Webcron and ajax both work but are
	 * reported as warnings: ajax in particular only runs when somebody browses
	 * the instance, which makes scheduled reporting unreliable.
	 *
	 * @return CheckResult
	 */
	private function checkCron(): array {
		$mode = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');

		if ($mode !== 'cron') {
			return $this->result(false, $mode, self::STATUS_WARN);
		}

		// Dichiarare la modalità cron non dice che il cron stia davvero girando.
		// `lastcron` è il momento dell'ultima esecuzione di cron.php ed è lo
		// stesso valore che Nextcloud mostra come "Ultima esecuzione".
		$lastCron = $this->appConfig->getValueInt('core', 'lastcron', 0);
		if ($lastCron <= 0) {
			return $this->result(false, 'cron, mai eseguito', self::STATUS_ERROR);
		}

		$minutes = (int)floor((time() - $lastCron) / 60);
		$value = 'cron, ultima ' . $minutes . ' min fa';

		// Il cron va eseguito almeno ogni 15 minuti. Un'ora di silenzio non è
		// più un ritardo: è fermo.
		return match (true) {
			$minutes > 60 => $this->result(false, $value, self::STATUS_ERROR),
			$minutes > 20 => $this->result(false, $value, self::STATUS_WARN),
			default => $this->result(true, $value, self::STATUS_OK),
		};
	}

	/**
	 * Arretrato della coda dei background job.
	 *
	 * Misura `last_checked`, non `last_run`. L'età dell'ultima esecuzione non
	 * dice nulla da sola, perché ogni job dichiara il proprio intervallo nel
	 * codice PHP e non nel database: `CleanupExpiredAuthorizationCode` gira ogni
	 * 30 giorni e `CheckCertificates` ogni 7, quindi un confronto con l'orologio
	 * li segnalerebbe come fermi per la quasi totalità del loro ciclo, su
	 * qualunque istanza sana.
	 *
	 * `last_checked` è invece il momento in cui Nextcloud ha valutato il job. Per
	 * un job non ancora dovuto viene spostato in avanti, alla prossima esecuzione
	 * utile: se resta indietro di oltre un giorno significa che la coda non viene
	 * smaltita, ed è l'unico guasto che si possa affermare guardando la tabella.
	 *
	 * @return CheckResult
	 */
	private function checkStaleJobs(): array {
		$threshold = time() - 24 * 3600;
		$int = IQueryBuilder::PARAM_INT;

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('jobs')
			->where($qb->expr()->lt('last_checked', $qb->createNamedParameter($threshold, $int)));
		$result = $qb->executeQuery();
		$arretrato = (int)$result->fetchOne();
		$result->closeCursor();

		if ($arretrato === 0) {
			return $this->result(true, 'backlog=0', self::STATUS_OK);
		}

		// L'età del più vecchio si legge ordinando, non con MIN(): l'alias di una
		// funzione aggregata non sopravvive a fetch() su tutti i driver, e un
		// valore mancante verrebbe letto come 0, cioè come "fermo dal 1970".
		$qb = $this->db->getQueryBuilder();
		$qb->select('last_checked')
			->from('jobs')
			->where($qb->expr()->lt('last_checked', $qb->createNamedParameter($threshold, $int)))
			->orderBy('last_checked', 'ASC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$oldest = (int)$result->fetchOne();
		$result->closeCursor();

		$hours = $oldest > 0 ? (int)floor((time() - $oldest) / 3600) : 0;
		$value = 'backlog=' . $arretrato . ($hours > 0 ? ', oldest_hours=' . $hours : '');

		// Oltre una settimana la coda non viene proprio smaltita.
		$status = $hours >= 168 ? self::STATUS_ERROR : self::STATUS_WARN;

		return $this->result(false, $value, $status);
	}

	/**
	 * Only the presence of the app can be established from inside Nextcloud.
	 * Verifying that the push endpoint actually answers requires an HTTP call
	 * that the app deliberately does not make, so a configured instance is
	 * reported as a warning rather than a pass.
	 *
	 * @return CheckResult
	 */
	private function checkNotifyPush(): array {
		if (!$this->apps->isEnabled('notify_push')) {
			return $this->result(false, 'not_installed', self::STATUS_WARN);
		}

		$endpoint = $this->appConfig->getValueString('notify_push', 'base_endpoint', '');
		if ($endpoint === '') {
			return $this->result(false, 'not_configured', self::STATUS_WARN);
		}

		return $this->result(true, 'configured', self::STATUS_OK);
	}

	/** @return CheckResult */
	private function checkTwoFactor(): array {
		$enforced = $this->appConfig->getValueString('twofactor_enforced', 'enforced', 'false');
		$isEnforced = $enforced === 'true';

		if ($isEnforced) {
			return $this->result(true, true, self::STATUS_OK);
		}

		$groups = $this->appConfig->getValueString('twofactor_enforced', 'enforced_groups', '');
		if ($groups !== '' && $groups !== '[]') {
			return $this->result(false, 'partial', self::STATUS_WARN);
		}

		return $this->result(false, false, self::STATUS_ERROR);
	}

	/** @return CheckResult */
	private function checkAppEnabled(string $appId, string $disabledStatus = self::STATUS_ERROR): array {
		$enabled = $this->apps->isEnabled($appId);
		return $this->result($enabled, $enabled, $enabled ? self::STATUS_OK : $disabledStatus);
	}

	/**
	 * A minimum length alone is weak; the check also rewards enforcing
	 * character classes and rejecting breached passwords.
	 *
	 * @return CheckResult
	 */
	private function checkPasswordPolicy(): array {
		if (!$this->apps->isEnabled('password_policy')) {
			return $this->result(false, 'app_disabled', self::STATUS_ERROR);
		}

		$minLength = (int)$this->appConfig->getValueString('password_policy', 'minLength', '10');
		$nonCommon = $this->appConfig->getValueString('password_policy', 'enforceNonCommonPassword', '1') === '1';
		$numeric = $this->appConfig->getValueString('password_policy', 'enforceNumericCharacters', '0') === '1';
		$special = $this->appConfig->getValueString('password_policy', 'enforceSpecialCharacters', '0') === '1';
		$upperLower = $this->appConfig->getValueString('password_policy', 'enforceUpperLowerCase', '0') === '1';

		$value = sprintf(
			'minLength=%d, nonCommon=%s, numeric=%s, special=%s, upperLower=%s',
			$minLength,
			$nonCommon ? 'yes' : 'no',
			$numeric ? 'yes' : 'no',
			$special ? 'yes' : 'no',
			$upperLower ? 'yes' : 'no',
		);

		$strong = $minLength >= 12 && $nonCommon && ($numeric || $special) && $upperLower;
		$acceptable = $minLength >= 10 && $nonCommon;

		if ($strong) {
			return $this->result(true, $value, self::STATUS_OK);
		}

		return $this->result(false, $value, $acceptable ? self::STATUS_WARN : self::STATUS_ERROR);
	}

	/**
	 * Server side encryption is not recommended for every deployment, so its
	 * absence is reported as information rather than as a failure.
	 *
	 * @return CheckResult
	 */
	private function checkEncryption(): array {
		$enabled = $this->appConfig->getValueString('core', 'encryption_enabled', 'no') === 'yes';
		return $this->result($enabled, $enabled, $enabled ? self::STATUS_OK : self::STATUS_WARN);
	}

	/** @return CheckResult */
	private function checkBruteForceAttempts(): array {
		// Counted from the failed login events rather than from Nextcloud's own
		// brute force table: with a distributed cache that table stays empty.
		$attempts = $this->failedLogins->countLast24Hours();

		if ($attempts === 0) {
			// Zero is only meaningful once a full day has been observed.
			return $this->failedLogins->hasFullWindow()
				? $this->result(true, 0, self::STATUS_OK)
				: $this->result(null, null, self::STATUS_UNKNOWN);
		}

		return $this->result(false, $attempts, $attempts >= 50 ? self::STATUS_ERROR : self::STATUS_WARN);
	}

	/** @param (callable(IQueryBuilder): void)|null $extraCondition */
	private function countPublicLinks(?callable $extraCondition = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('share')
			->where($qb->expr()->eq(
				'share_type',
				$qb->createNamedParameter(\OCP\Share\IShare::TYPE_LINK, IQueryBuilder::PARAM_INT),
			));

		if ($extraCondition !== null) {
			$extraCondition($qb);
		}

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/** @return CheckResult */
	private function checkLinksWithoutExpiry(): array {
		$count = $this->countPublicLinks(static function (IQueryBuilder $qb): void {
			$qb->andWhere($qb->expr()->isNull('expiration'));
		});

		if ($count === 0) {
			return $this->result(true, 0, self::STATUS_OK);
		}

		return $this->result(false, $count, $count >= 5 ? self::STATUS_ERROR : self::STATUS_WARN);
	}

	/** @return CheckResult */
	private function checkLinksWithoutPassword(): array {
		$count = $this->countPublicLinks(static function (IQueryBuilder $qb): void {
			$qb->andWhere($qb->expr()->isNull('password'));
		});

		if ($count === 0) {
			return $this->result(true, 0, self::STATUS_OK);
		}

		return $this->result(false, $count, $count >= 5 ? self::STATUS_ERROR : self::STATUS_WARN);
	}

	/**
	 * Share policy keys are declared as booleans in the config lexicon and must
	 * be read with the matching typed getter, otherwise Nextcloud throws.
	 *
	 * @return CheckResult
	 */
	private function checkSharePasswordEnforced(): array {
		$enforced = $this->appConfig->getValueBool('core', 'shareapi_enforce_links_password', false);
		return $this->result($enforced, $enforced, $enforced ? self::STATUS_OK : self::STATUS_WARN);
	}

	/** @return CheckResult */
	private function checkMemcache(): array {
		$local = $this->config->getSystemValueString('memcache.local', '');
		$locking = $this->config->getSystemValueString('memcache.locking', '');

		if ($local === '') {
			return $this->result(false, 'none', self::STATUS_ERROR);
		}

		$value = 'local=' . $this->shortClass($local);
		if ($locking !== '') {
			$value .= ', locking=' . $this->shortClass($locking);
			return $this->result(true, $value, self::STATUS_OK);
		}

		return $this->result(false, $value . ', locking=none', self::STATUS_WARN);
	}

	private function shortClass(string $fqcn): string {
		$parts = explode('\\', $fqcn);
		return end($parts) ?: $fqcn;
	}

	/** @return CheckResult */
	private function checkDebugDisabled(): array {
		$debug = $this->config->getSystemValueBool('debug', false);
		return $this->result(!$debug, $debug, $debug ? self::STATUS_ERROR : self::STATUS_OK);
	}

	/**
	 * The ini directive is read rather than the runtime state: the reporting job
	 * runs under the CLI SAPI, where opcache is normally off even when it is
	 * correctly enabled for the web server.
	 *
	 * @return CheckResult
	 */
	private function checkOpcache(): array {
		$enabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
		return $this->result($enabled, $enabled, $enabled ? self::STATUS_OK : self::STATUS_WARN);
	}

	/**
	 * An office integration is either pointed at a dedicated server, still on the
	 * engine bundled with the app, or installed without any server at all. The
	 * last two both ask for attention but for different reasons, so the value
	 * tells them apart ('dedicated', 'builtin', 'not_configured').
	 *
	 * @return CheckResult
	 */
	private function documentServerResult(string $url, string $builtinMarker): array {
		if ($url === '') {
			return $this->result(false, 'not_configured', self::STATUS_WARN);
		}

		if (str_contains($url, $builtinMarker)) {
			return $this->result(false, 'builtin', self::STATUS_WARN);
		}

		return $this->result(true, 'dedicated', self::STATUS_OK);
	}

	/**
	 * Checks that apply only when the corresponding integration is installed.
	 *
	 * @return array<string, CheckResult>
	 */
	/** @return array<string, CheckResult> */
	private function getOptionalIntegrationChecks(): array {
		$results = [];

		if ($this->apps->isEnabled('onlyoffice')) {
			$results['onlyoffice_builtin'] = $this->safely('onlyoffice_builtin', fn (): array => $this->documentServerResult(
				$this->appConfig->getValueString('onlyoffice', 'DocumentServerUrl', ''),
				'/apps/onlyoffice/',
			));
		}

		if ($this->apps->isEnabled('richdocuments')) {
			$results['collabora_builtin'] = $this->safely('collabora_builtin', fn (): array => $this->documentServerResult(
				$this->appConfig->getValueString('richdocuments', 'wopi_url', ''),
				'richdocumentscode',
			));
		}

		if ($this->apps->isEnabled('spreed')) {
			$results['talk_turn_configured'] = $this->safely('talk_turn_configured', function () {
				$turn = $this->appConfig->getValueString('spreed', 'turn_servers', '');
				$signaling = $this->appConfig->getValueString('spreed', 'signaling_servers', '');
				$configured = ($turn !== '' && $turn !== '[]') || ($signaling !== '' && $signaling !== '[]');
				return $this->result($configured, $configured, $configured ? self::STATUS_OK : self::STATUS_WARN);
			});
		}

		return $results;
	}
}
