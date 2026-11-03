<?php

declare(strict_types=1);

namespace OCA\NCCare\Service;

use OCA\NCCare\AppInfo\Application;
use OCA\NCCare\Config\AppConfig;
use OCA\NCCare\Exception\InstallationPendingException;
use OCA\NCCare\Exception\InstallationRejectedException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** @psalm-api */
class RegistrationService {

	/** Held while a round registers and reports: two at once would register the same instance twice. */
	private const string LOCK = 'nccare/registration';

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IClientService $clientService,
		private readonly AppConfig $itsConfig,
		private readonly SystemInfoService $systemInfoService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $locks,
	) {
	}

	public function isRegistered(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'installation_token', '') !== '';
	}

	public function register(): void {
		$backendUrl = $this->itsConfig->getBackendUrl();

		$installationId = $this->appConfig->getValueString(Application::APP_ID, 'installation_id', '');
		if ($installationId === '') {
			$installationId = $this->generateUuid();
			$this->appConfig->setValueString(Application::APP_ID, 'installation_id', $installationId);
		}

		$payload = [
			'installation_id' => $installationId,
			'domain' => $this->systemInfoService->getDomain(),
			'nextcloud_version' => $this->systemInfoService->getNextcloudVersion(),
			'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'app_version' => AppConfig::APP_VERSION,
			'report_version' => AppConfig::REPORT_VERSION,
			'timestamp' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
		];

		$this->logger->info('NC Care:registering with payload', [
			'app' => Application::APP_ID,
			'payload' => $payload,
			'url' => $backendUrl . '/api/v1/installations/register',
		]);

		$client = $this->clientService->newClient();
		try {
			$response = $client->post($backendUrl . '/api/v1/installations/register', [
				'json' => $payload,
				'timeout' => 30,
				'allow_redirects' => true,
			]);
		} catch (\Throwable $e) {
			// An installation disabled by the support team is refused on purpose: report it as such and not as a
			// generic refusal, which is what a bare 403 would be taken for.
			if ($this->statusOf($e) === 403 && $this->errorCodeOf($e) === 'installation_disabled') {
				throw new InstallationRejectedException(403, $e);
			}
			throw $e;
		}

		$rawBody = (string)$response->getBody();
		$this->logger->debug('NC Care:registration response', [
			'app' => Application::APP_ID,
			'status' => $response->getStatusCode(),
			'body' => $rawBody,
		]);

		$body = json_decode($rawBody, true);
		if (!is_array($body) || !isset($body['installation_token'])) {
			// The raw body may carry internal details, so it goes to the log and
			// not into the exception that ends up in front of the administrator.
			$this->logger->error('NC Care:unexpected registration response', [
				'app' => Application::APP_ID,
				'body' => $rawBody,
			]);
			throw new \RuntimeException('unexpected_response');
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			'installation_token',
			(string)$body['installation_token'],
			sensitive: true,
		);
		// A new installation starts "pending": it may send its first report and then waits for approval.
		$this->setApprovalPending(($body['installation_status'] ?? null) === 'pending');
		$this->saveSuccess();
	}

	/**
	 * Sends the report to the v2 endpoint, falling back to v1 when the backend
	 * does not expose it yet.
	 *
	 * @param array<string, mixed> $systemInfo
	 */
	public function sendReport(array $systemInfo): void {
		$backendUrl = $this->itsConfig->getBackendUrl();
		$installationToken = $this->appConfig->getValueString(Application::APP_ID, 'installation_token', '');

		if ($installationToken === '') {
			throw new \RuntimeException('App not registered');
		}

		$payload = array_merge($systemInfo, [
			'installation_id' => $this->appConfig->getValueString(Application::APP_ID, 'installation_id', ''),
			'app_version' => AppConfig::APP_VERSION,
			'timestamp' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
		]);

		$client = $this->clientService->newClient();
		$options = [
			'json' => $payload,
			'timeout' => 30,
			'allow_redirects' => true,
			'headers' => [
				'Authorization' => 'Bearer ' . $installationToken,
			],
		];

		$this->logger->info('NC Care:sending report', [
			'app' => Application::APP_ID,
			'url' => $backendUrl . '/api/v2/reports',
		]);

		try {
			$response = $client->post($backendUrl . '/api/v2/reports', $options);
		} catch (\Throwable $e) {
			$status = $this->statusOf($e);

			// 401/403 are the backend's answer about this installation, not about the
			// endpoint: asking v1 with the same token would only repeat the refusal.
			if ($status === 401 || $status === 403) {
				throw $this->refusal($status, $e);
			}

			// v1 only when v2 is not there (404/405) or the request never got an
			// answer (network error); any other answer from v2 is final.
			if ($status !== null && $status !== 404 && $status !== 405) {
				throw $e;
			}

			$this->logger->info(
				'NC Care:v2 endpoint unavailable, falling back to v1: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);

			try {
				$response = $client->post($backendUrl . '/api/v1/reports', $options);
			} catch (\Throwable $v1) {
				$v1Status = $this->statusOf($v1);
				if ($v1Status === 401 || $v1Status === 403) {
					throw $this->refusal($v1Status, $v1);
				}
				throw $v1;
			}
		}

		$rawBody = (string)$response->getBody();
		$this->logger->debug('NC Care:report response', [
			'app' => Application::APP_ID,
			'status' => $response->getStatusCode(),
			'body' => $rawBody,
		]);

		$this->saveSuccess();

		if ($this->itsConfig->isDebugEnabled()) {
			$this->appConfig->setValueString(
				Application::APP_ID,
				'last_payload',
				(string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
			);
		}
	}

	/**
	 * One round of the periodic work: register if the installation is not yet,
	 * and send the report.
	 *
	 * A new installation sends its first report right after registering, so the
	 * support panel has data straight away instead of after the next scheduled
	 * round. Should that first report fail, the registration stays (the token is
	 * already stored) and the error is left to the caller to record; the next
	 * round sends the report.
	 *
	 * When the backend no longer knows the token (it was recreated, cleaned or
	 * the installation was removed) the token is dropped and the installation
	 * registers again, once, and the report goes out right away. A refusal that
	 * survives the new registration, or an explicit 403, is not worked around: it
	 * is reported as it is.
	 *
	 * A new installation is "pending": the backend accepts its first report (sent right after registering) and
	 * then asks ITServicenet to approve it. While it waits the backend refuses further reports with
	 * `installation_pending`: that is not an error, the round ends as 'pending' and the next one tries again.
	 *
	 * Only one round runs at a time. The background job and an administrator's click (or two tabs) can start
	 * together on a new instance: both would find no installation id, each would make up its own and register,
	 * and the backend would list the same instance twice. The second one finds the lock taken and does nothing.
	 *
	 * @return 'registered'|'report_sent'|'pending'|'busy'
	 * @throws \Throwable whatever stopped the round, for the caller to record
	 */
	public function synchronise(): string {
		try {
			$this->locks->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE, 'NC Care registration');
		} catch (LockedException) {
			return 'busy';
		}

		try {
			return $this->synchroniseLocked();
		} finally {
			$this->locks->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return 'registered'|'report_sent'|'pending' */
	private function synchroniseLocked(): string {
		if (!$this->isRegistered()) {
			$this->register();
			$this->sendReport($this->systemInfoService->getSystemInfo());
			return 'registered';
		}

		$systemInfo = $this->systemInfoService->getSystemInfo();

		try {
			return $this->reportOrWait($systemInfo);
		} catch (InstallationRejectedException $e) {
			if ($e->getStatus() !== 401) {
				throw $e;
			}

			$this->logger->warning('NC Care:the backend does not recognise the installation token, registering again', [
				'app' => Application::APP_ID,
			]);
			$this->appConfig->deleteKey(Application::APP_ID, 'installation_token');
			$this->register();

			// Still refused with a brand new token: the installation itself is not
			// accepted (for instance disabled by the support team). No third try. A new registration may be
			// pending: its first report is accepted all the same, and that must not clear the pending mark.
			return $this->reportOrWait($systemInfo, keepPendingMark: true);
		}
	}

	/**
	 * Sends the report; an installation waiting for approval is a state, not a failure.
	 *
	 * @param array<string, mixed> $systemInfo
	 * @return 'report_sent'|'pending'
	 */
	private function reportOrWait(array $systemInfo, bool $keepPendingMark = false): string {
		try {
			$this->sendReport($systemInfo);
		} catch (InstallationPendingException) {
			$this->setApprovalPending(true);
			return 'pending';
		}

		if (!$keepPendingMark) {
			$this->setApprovalPending(false);
		}
		return 'report_sent';
	}

	/**
	 * While the installation waits for approval, asks the backend where it stands. Once it is approved the mark goes
	 * and the report that was held back is sent right away, so the settings page does not wait for the next
	 * scheduled round to say "registered". Best effort: any failure leaves things as they are and the daily job
	 * finds out on its own.
	 */
	public function refreshApprovalStatus(): void {
		$token = $this->appConfig->getValueString(Application::APP_ID, 'installation_token', '');
		if ($token === '' || $this->appConfig->getValueString(Application::APP_ID, 'approval_pending', '') !== '1') {
			return;
		}

		try {
			$response = $this->clientService->newClient()->get(
				$this->itsConfig->getBackendUrl() . '/api/v1/installation/status',
				['timeout' => 5, 'headers' => ['Authorization' => 'Bearer ' . $token]],
			);
			/** @psalm-suppress MixedAssignment json_decode() returns mixed by design; the shape is checked on the next line */
			$body = json_decode((string)$response->getBody(), true);
			if (!is_array($body) || ($body['status'] ?? null) !== 'active') {
				return;
			}

			$this->setApprovalPending(false);
			$this->sendReport($this->systemInfoService->getSystemInfo());
		} catch (\Throwable $e) {
			$this->logger->info('NC Care:could not refresh the approval status: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	/** The refusal of a report, told apart: waiting for approval, or not accepted. */
	private function refusal(int $status, \Throwable $e): \RuntimeException {
		if ($status === 403 && $this->errorCodeOf($e) === 'installation_pending') {
			return new InstallationPendingException($e);
		}

		return new InstallationRejectedException($status, $e);
	}

	private function setApprovalPending(bool $pending): void {
		$this->appConfig->setValueString(Application::APP_ID, 'approval_pending', $pending ? '1' : '');
	}

	public function testConnection(): bool {
		$backendUrl = $this->itsConfig->getBackendUrl();
		$this->logger->info('NC Care:testing connection', [
			'app' => Application::APP_ID,
			'url' => $backendUrl . '/api/v1/ping',
		]);

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($backendUrl . '/api/v1/ping', ['timeout' => 10]);
			/** @psalm-suppress MixedAssignment json_decode() returns mixed by design; the shape is checked on the next line */
			$body = json_decode((string)$response->getBody(), true);
			$ok = is_array($body) && isset($body['ok']) && $body['ok'] === true;
			$this->logger->info('NC Care:ping result: ' . ($ok ? 'OK' : 'FAIL'), ['app' => Application::APP_ID]);
			return $ok;
		} catch (\Throwable $e) {
			$this->logger->warning('NC Care:ping failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return false;
		}
	}

	private function saveSuccess(): void {
		$now = (new \DateTime())->format(\DateTime::ATOM);
		$this->appConfig->setValueString(Application::APP_ID, 'last_success_at', $now);
		$this->appConfig->setValueString(Application::APP_ID, 'last_error_at', '');
		$this->appConfig->setValueString(Application::APP_ID, 'last_error_message', '');
	}

	public function saveError(\Throwable $e): void {
		$now = (new \DateTime())->format(\DateTime::ATOM);
		$this->appConfig->setValueString(Application::APP_ID, 'last_error_at', $now);
		$this->appConfig->setValueString(Application::APP_ID, 'last_error_message', $this->describeFailure($e));

		// The untouched exception stays in the Nextcloud log, where the full
		// detail is useful and the audience is the operator, not the customer.
		$this->logger->error('NC Care:' . $e->getMessage(), [
			'app' => Application::APP_ID,
			'exception' => $e,
		]);
	}

	/**
	 * Turns an exception into a message fit for the settings page.
	 *
	 * HTTP client exceptions quote the request URL, which exposes the internal
	 * address of the support backend to the instance administrator. The cause is
	 * therefore reported in plain terms and the address never leaves the log.
	 */
	public function describeFailure(\Throwable $e): string {
		if ($e instanceof \OCP\Http\Client\LocalServerException) {
			return $this->l10n->t('The support backend is on a local address that Nextcloud refuses to contact. Enable allow_local_remote_servers in config.php, or use a public address.');
		}

		// A report refused with a token the backend gave us: after a new
		// registration this means the installation itself is not accepted, and with
		// a 403 it was refused on purpose. Either way it is about this installation
		// and not about the registration.
		if ($e instanceof InstallationPendingException) {
			return $this->l10n->t('This instance is waiting for approval by ITServicenet.');
		}

		if ($e instanceof InstallationRejectedException) {
			return $this->l10n->t('The support backend does not accept reports from this installation. It may have been disabled: contact ITServicenet.');
		}

		$status = $this->statusOf($e);

		if ($status !== null) {
			return match (true) {
				$status === 401 || $status === 403 => $this->l10n->t('The support backend refused the request.'),
				$status === 404 => $this->l10n->t('The support backend does not expose the registration endpoint.'),
				$status === 429 => $this->l10n->t('Too many attempts. Wait a few minutes before retrying.'),
				$status >= 500 => $this->l10n->t('The support backend reported an internal error (HTTP %s).', [$status]),
				default => $this->l10n->t('The support backend refused the request (HTTP %s).', [$status]),
			};
		}

		if ($e->getMessage() === 'unexpected_response') {
			return $this->l10n->t('The support backend answered with an unexpected response. The details are in the Nextcloud log.');
		}

		if (str_contains($e->getMessage(), 'cURL error') || str_contains($e->getMessage(), 'Connection refused')) {
			return $this->l10n->t('The support backend could not be reached. Check that it is running and reachable from this server.');
		}

		// Last resort: keep the message but strip anything that looks like a URL.
		$stripped = trim((string)preg_replace('#\bhttps?://\S+#i', '[…]', $e->getMessage()));
		return $stripped !== '' ? $stripped : $this->l10n->t('Unexpected error.');
	}

	/**
	 * The HTTP status carried by a client exception, or null when there is none
	 * (network failure, not an HTTP error). The HTTP client throws Guzzle
	 * exceptions, which the app does not depend on, so the response is read by
	 * duck typing rather than by class.
	 */
	private function statusOf(\Throwable $e): ?int {
		if (!method_exists($e, 'getResponse')) {
			return null;
		}

		/** @psalm-suppress MixedAssignment the exception class is not known: read by duck typing */
		$response = $e->getResponse();
		if (is_object($response) && method_exists($response, 'getStatusCode')) {
			return (int)$response->getStatusCode();
		}

		return null;
	}

	/**
	 * The machine-readable error code of an API error response (`error.code`), or null when the exception carries
	 * no readable body. Read by duck typing, like statusOf().
	 */
	private function errorCodeOf(\Throwable $e): ?string {
		if (!method_exists($e, 'getResponse')) {
			return null;
		}

		/** @psalm-suppress MixedAssignment the exception class is not known: read by duck typing */
		$response = $e->getResponse();
		if (!is_object($response) || !method_exists($response, 'getBody')) {
			return null;
		}

		/** @psalm-suppress MixedAssignment the response class is not known */
		$body = json_decode((string)$response->getBody(), true);
		/** @psalm-suppress MixedAssignment the body is JSON from the backend: its shape is checked right below */
		$code = is_array($body) && is_array($body['error'] ?? null) ? ($body['error']['code'] ?? null) : null;

		return is_string($code) ? $code : null;
	}

	private function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
