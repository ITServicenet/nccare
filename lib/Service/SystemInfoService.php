<?php

declare(strict_types=1);

namespace OCA\NCCare\Service;

use OCA\NCCare\Compat\AppAvailability;
use OCA\NCCare\Config\AppConfig;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/** @psalm-api */
class SystemInfoService {

	public function __construct(
		private readonly IConfig $config,
		private readonly \OCP\IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly AppAvailability $apps,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IDBConnection $db,
		private readonly AppConfig $itsConfig,
		private readonly ComplianceCheckService $complianceCheckService,
		private readonly ServerInfoService $serverInfoService,
	) {
	}

	/**
	 * Full report payload, version 2.
	 *
	 * @return array<string, mixed>
	 */
	public function getSystemInfo(): array {
		return [
			'report_version' => AppConfig::REPORT_VERSION,
			'instance' => $this->getInstanceInfo(),
			'users' => $this->getUserInfo(),
			'apps' => $this->getAppInfo(),
			'checks' => $this->complianceCheckService->getAllChecks(),
			'serverinfo' => $this->serverInfoService->getServerInfo(),

			// Flat fields kept for backward compatibility with the v1 endpoint.
			'nextcloud_version' => $this->getNextcloudVersion(),
			'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'db_type' => $this->getDatabaseType(),
			'users_total' => $this->getTotalUsers(),
			'users_active' => $this->getActiveUsers(),
			'background_job_mode' => $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax'),
			'apps_enabled' => $this->getEnabledApps(),
			'domain' => $this->getDomain(),
		];
	}

	/** @return array<string, mixed> */
	private function getInstanceInfo(): array {
		return [
			'nextcloud_version' => $this->getNextcloudVersion(),
			'nextcloud_versionstring' => $this->config->getSystemValueString('version', ''),
			'update_available' => $this->getAvailableUpdate(),
			'update_channel' => $this->getUpdateChannel(),
			'php_version' => PHP_VERSION,
			'db_type' => $this->getDatabaseType(),
			'domain' => $this->getDomain(),
			'has_valid_subscription' => $this->config->getSystemValueBool('has_valid_subscription', false),
			'maintenance' => $this->config->getSystemValueBool('maintenance', false),
			'loglevel' => $this->config->getSystemValueInt('loglevel', 2),
		];
	}

	/** @return array<string, mixed> */
	private function getUserInfo(): array {
		return [
			'total' => $this->getTotalUsers(),
			'active_30d' => $this->getActiveUsers(),
			'disabled' => $this->getDisabledUsers(),
			'admins' => $this->getAdminCount(),
			'groups' => $this->getGroupCount(),
		];
	}

	/** @return array<string, mixed> */
	private function getAppInfo(): array {
		$enabled = $this->getEnabledApps();
		$custom = [];
		$versions = [];

		foreach ($enabled as $appId) {
			try {
				$versions[$appId] = $this->appManager->getAppVersion($appId);
				if (!$this->appManager->isShipped($appId)) {
					$custom[] = $appId;
				}
			} catch (\Throwable) {
				// An app with broken metadata must not abort the whole report.
				continue;
			}
		}

		return [
			'enabled_count' => count($enabled),
			'enabled' => $enabled,
			'custom' => $custom,
			'versions' => $versions,
			// Solo fatti verificabili: app installata sì/no, config effettiva
			// letta da IAppConfig. Niente euristiche su hostname/porte/env —
			// se un dato non si può accertare con certezza, non si riporta.
			'talk_enabled' => $this->apps->isEnabled('spreed'),
			'talk_hpb_configured' => $this->isTalkHpbConfigured(),
			'collabora_enabled' => $this->apps->isEnabled('richdocuments'),
			// "Collabora Online - Built-in CODE Server": il motore integrato a
			// basso scalamento incluso in Nextcloud, distinto da un server
			// Collabora esterno dedicato. È un'app a sé (richdocumentscode),
			// non un'ipotesi: se è abilitata, lo si sa con certezza.
			'collabora_builtin_code_server' => $this->apps->isEnabled('richdocumentscode'),
			'onlyoffice_enabled' => $this->apps->isEnabled('onlyoffice'),
		];
	}

	/**
	 * Se per Talk è configurato un signaling server esterno (il "motore ad
	 * alte prestazioni", HPB) invece del solo signaling interno.
	 *
	 * Letto direttamente dalla configurazione salvata da Talk stesso
	 * (`spreed.signaling_servers`, un JSON con l'elenco dei server): un dato
	 * certo, non un'ipotesi. Nessun server esterno configurato o Talk non
	 * abilitato → false.
	 */
	private function isTalkHpbConfigured(): bool {
		if (!$this->apps->isEnabled('spreed')) {
			return false;
		}

		$raw = $this->appConfig->getValueString('spreed', 'signaling_servers', '');
		if ($raw === '') {
			return false;
		}

		/** @var mixed $decoded */
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return false;
		}

		/** @var array<string, mixed> $decoded */
		return !empty($decoded['servers']);
	}

	/**
	 * Resolves the public address of the instance.
	 *
	 * The background job runs under the CLI SAPI, where no request host is
	 * available, so the strategies are tried in order of reliability: an
	 * explicit manual override first, then the Nextcloud overwrite settings,
	 * then the host captured during a real web request, and finally the first
	 * trusted domain that is not a loopback name.
	 */
	public function getDomain(): string {
		$override = $this->itsConfig->getDomainOverride();
		if ($override !== '') {
			return rtrim($override, '/');
		}

		$cliUrl = $this->config->getSystemValueString('overwrite.cli.url', '');
		if ($cliUrl !== '') {
			return rtrim($cliUrl, '/');
		}

		$host = $this->config->getSystemValueString('overwritehost', '');
		if ($host !== '') {
			$protocol = $this->config->getSystemValueString('overwriteprotocol', 'https');
			return $protocol . '://' . $host;
		}

		$detected = $this->itsConfig->getDetectedDomain();
		if ($detected !== '') {
			return rtrim($detected, '/');
		}

		return $this->getFirstUsableTrustedDomain();
	}

	private function getFirstUsableTrustedDomain(): string {
		$trusted = $this->config->getSystemValue('trusted_domains', []);
		if (!is_array($trusted)) {
			return '';
		}

		$loopback = ['localhost', '127.0.0.1', '::1'];
		foreach ($trusted as $domain) {
			if (!is_string($domain) || $domain === '') {
				continue;
			}
			if (in_array($domain, $loopback, true) || str_contains($domain, '*')) {
				continue;
			}
			$protocol = $this->config->getSystemValueString('overwriteprotocol', 'https');
			return $protocol . '://' . $domain;
		}

		return '';
	}

	/** True when no strategy could determine the address of the instance. */
	public function isDomainResolved(): bool {
		return $this->getDomain() !== '';
	}

	public function getNextcloudVersion(): string {
		return implode('.', array_map(
			static fn (mixed $part): string => is_scalar($part) ? (string)$part : '',
			$this->getServerVersionParts(),
		));
	}

	/**
	 * Version of the server as [major, minor, patch, build]. OCP\ServerVersion exists from
	 * Nextcloud 31 (OCP\Util::getVersion() is deprecated since then); Nextcloud 30 only has the old one.
	 *
	 * @return array<array-key, mixed>
	 *
	 * @psalm-suppress MixedInferredReturnType the 30 stubs do not know OCP\ServerVersion, so that branch is mixed there
	 */
	private function getServerVersionParts(): array {
		if (class_exists(\OCP\ServerVersion::class)) {
			/**
			 * @psalm-suppress UndefinedClass the class is not in the stubs of Nextcloud 30, guarded by class_exists()
			 * @psalm-suppress MixedMethodCall same reason
			 * @psalm-suppress MixedReturnStatement same reason
			 */
			return \OCP\Server::get(\OCP\ServerVersion::class)->getVersion();
		}

		/** @psalm-suppress DeprecatedMethod only reached on Nextcloud 30, which has no ServerVersion */
		return \OCP\Util::getVersion();
	}

	/**
	 * Version offered by the update server, when newer than the installed one.
	 */
	private function getAvailableUpdate(): ?string {
		$raw = $this->appConfig->getValueString('core', 'lastupdateResult', '');
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded) || !isset($decoded['version']) || !is_string($decoded['version'])) {
			return null;
		}

		$available = $decoded['version'];
		$installed = $this->config->getSystemValueString('version', '');

		if ($installed !== '' && version_compare($available, $installed, '<=')) {
			return null;
		}

		return $available;
	}

	/**
	 * @psalm-suppress MixedInferredReturnType the 30 stubs do not know OCP\ServerVersion, so that branch is mixed there
	 */
	private function getUpdateChannel(): string {
		try {
			if (class_exists(\OCP\ServerVersion::class)) {
				/**
				 * @psalm-suppress UndefinedClass the class is not in the stubs of Nextcloud 30, guarded by class_exists()
				 * @psalm-suppress MixedMethodCall same reason
				 * @psalm-suppress MixedReturnStatement same reason
				 */
				return \OCP\Server::get(\OCP\ServerVersion::class)->getChannel();
			}

			/** @psalm-suppress DeprecatedMethod only reached on Nextcloud 30, which has no ServerVersion */
			return \OCP\Util::getChannel();
		} catch (\Throwable) {
			return '';
		}
	}

	private function getDatabaseType(): string {
		$dbType = $this->config->getSystemValueString('dbtype', 'sqlite3');
		return match ($dbType) {
			'mysql' => 'mariadb',
			'pgsql' => 'postgresql',
			'sqlite3', 'sqlite' => 'sqlite',
			default => $dbType,
		};
	}

	private function getTotalUsers(): int {
		$count = 0;
		$this->userManager->callForAllUsers(function () use (&$count): void {
			$count++;
		});
		return $count;
	}

	private function getDisabledUsers(): int {
		try {
			return $this->userManager->countDisabledUsers();
		} catch (\Throwable) {
			return 0;
		}
	}

	private function getAdminCount(): int {
		try {
			$group = $this->groupManager->get('admin');
			return $group === null ? 0 : count($group->getUsers());
		} catch (\Throwable) {
			return 0;
		}
	}

	private function getGroupCount(): int {
		try {
			return count($this->groupManager->search(''));
		} catch (\Throwable) {
			return 0;
		}
	}

	private function getActiveUsers(): int {
		$thirtyDaysAgo = time() - (30 * 24 * 3600);
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('login')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('lastLogin')))
			->andWhere($qb->expr()->gt('configvalue', $qb->createNamedParameter((string)$thirtyDaysAgo)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/** @return list<string> */
	private function getEnabledApps(): array {
		return $this->apps->getEnabledApps();
	}
}
