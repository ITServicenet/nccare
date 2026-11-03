<?php

declare(strict_types=1);

namespace OCA\NCCare\Controller;

use OCA\NCCare\AppInfo\Application;
use OCA\NCCare\Config\AppConfig;
use OCA\NCCare\Service\ComplianceCheckService;
use OCA\NCCare\Service\RegistrationService;
use OCA\NCCare\Service\SystemInfoService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IAppConfig;
use OCP\IRequest;

/** @psalm-api */
class AdminSettingsController extends OCSController {

	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly AppConfig $itsConfig,
		private readonly RegistrationService $registrationService,
		private readonly SystemInfoService $systemInfoService,
		private readonly ComplianceCheckService $complianceCheckService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Get current status and configuration
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 */
	/** @psalm-suppress InvalidArgument AuthorizedAdminSetting types the argument as IDelegatedSettings; AdminSettings is a plain ISettings */
	#[AuthorizedAdminSetting(settings: \OCA\NCCare\Settings\AdminSettings::class)]
	#[ApiRoute(verb: 'GET', url: '/admin/settings')]
	public function getSettings(): DataResponse {
		// This endpoint is served from a real web request, which is the only
		// context where the public host is known. The value is persisted so the
		// reporting job, running under CLI, can reuse it.
		$this->captureDomainFromRequest();

		// Waiting for approval: ask the backend, so a page opened after the approval already shows "registered".
		$this->registrationService->refreshApprovalStatus();

		$installationToken = $this->appConfig->getValueString(Application::APP_ID, 'installation_token', '');
		$lastErrorAt = $this->appConfig->getValueString(Application::APP_ID, 'last_error_at', '');
		// A new installation waits for ITServicenet to approve it: it is registered, but its reports are held back.
		$approvalPending = $installationToken !== ''
			&& $this->appConfig->getValueString(Application::APP_ID, 'approval_pending', '') === '1';

		if ($installationToken !== '') {
			$status = $approvalPending ? 'pending' : 'registered';
		} elseif ($lastErrorAt !== '') {
			$status = 'error';
		} else {
			$status = 'not_registered';
		}

		return new DataResponse([
			'installation_id' => $this->appConfig->getValueString(Application::APP_ID, 'installation_id', ''),
			'status' => $status,
			// Distinct from the status: an instance whose registration failed is
			// in the "error" state but still needs registering, and the action
			// offered to the administrator has to reflect that.
			'registered' => $installationToken !== '',
			'approval_pending' => $approvalPending,
			'last_success_at' => $this->appConfig->getValueString(Application::APP_ID, 'last_success_at', ''),
			'last_error_at' => $lastErrorAt,
			'last_error_message' => $this->appConfig->getValueString(Application::APP_ID, 'last_error_message', ''),
			'app_version' => AppConfig::APP_VERSION,
			// Sign-up page of the support portal, derived from the configured
			// portal address so a different deployment points at its own.
			'register_url' => $this->itsConfig->getPortalUrl() . '/register',
			'domain' => $this->systemInfoService->getDomain(),
			'domain_resolved' => $this->systemInfoService->isDomainResolved(),
		]);
	}

	/**
	 * Security and compliance review data
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 */
	/** @psalm-suppress InvalidArgument AuthorizedAdminSetting types the argument as IDelegatedSettings; AdminSettings is a plain ISettings */
	#[AuthorizedAdminSetting(settings: \OCA\NCCare\Settings\AdminSettings::class)]
	#[ApiRoute(verb: 'GET', url: '/admin/checks')]
	public function getChecks(): DataResponse {
		$checks = $this->complianceCheckService->getAllChecks();

		$summary = ['ok' => 0, 'warn' => 0, 'error' => 0, 'unknown' => 0];
		foreach ($checks as $check) {
			$status = $check['status'] ?? ComplianceCheckService::STATUS_UNKNOWN;
			if (isset($summary[$status])) {
				$summary[$status]++;
			}
		}

		// Hardware statistics keep travelling with the report so the support
		// panel can show them, but they are no longer rendered here.
		return new DataResponse([
			'checks' => $checks,
			'summary' => $summary,
		]);
	}

	/**
	 * Run report/registration immediately
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_INTERNAL_SERVER_ERROR, array{status: string, message?: string}, array{}>
	 */
	/** @psalm-suppress InvalidArgument AuthorizedAdminSetting types the argument as IDelegatedSettings; AdminSettings is a plain ISettings */
	#[AuthorizedAdminSetting(settings: \OCA\NCCare\Settings\AdminSettings::class)]
	#[ApiRoute(verb: 'POST', url: '/admin/run-now')]
	public function runNow(): DataResponse {
		try {
			$this->captureDomainFromRequest();

			return new DataResponse(['status' => $this->registrationService->synchronise()]);
		} catch (\Throwable $e) {
			$this->registrationService->saveError($e);
			return new DataResponse(
				['status' => 'error', 'message' => $this->registrationService->describeFailure($e)],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}

	/**
	 * Preview the payload that would be sent to the backend
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 */
	/** @psalm-suppress InvalidArgument AuthorizedAdminSetting types the argument as IDelegatedSettings; AdminSettings is a plain ISettings */
	#[AuthorizedAdminSetting(settings: \OCA\NCCare\Settings\AdminSettings::class)]
	#[ApiRoute(verb: 'GET', url: '/admin/preview-payload')]
	public function previewPayload(): DataResponse {
		return new DataResponse($this->systemInfoService->getSystemInfo());
	}

	/**
	 * Test connection to backend
	 *
	 * @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 */
	/** @psalm-suppress InvalidArgument AuthorizedAdminSetting types the argument as IDelegatedSettings; AdminSettings is a plain ISettings */
	#[AuthorizedAdminSetting(settings: \OCA\NCCare\Settings\AdminSettings::class)]
	#[ApiRoute(verb: 'GET', url: '/admin/test-connection')]
	public function testConnection(): DataResponse {
		$success = $this->registrationService->testConnection();
		return new DataResponse(['success' => $success]);
	}

	/**
	 * Retry registration (reset token and re-register)
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_INTERNAL_SERVER_ERROR, array{status: string, message?: string}, array{}>
	 */
	/** @psalm-suppress InvalidArgument AuthorizedAdminSetting types the argument as IDelegatedSettings; AdminSettings is a plain ISettings */
	#[AuthorizedAdminSetting(settings: \OCA\NCCare\Settings\AdminSettings::class)]
	#[ApiRoute(verb: 'POST', url: '/admin/retry-registration')]
	public function retryRegistration(): DataResponse {
		$this->appConfig->setValueString(Application::APP_ID, 'installation_id', '');
		$this->appConfig->setValueString(Application::APP_ID, 'installation_token', '');
		$this->appConfig->setValueString(Application::APP_ID, 'approval_pending', '');
		$this->appConfig->setValueString(Application::APP_ID, 'last_success_at', '');
		$this->appConfig->setValueString(Application::APP_ID, 'last_error_at', '');
		$this->appConfig->setValueString(Application::APP_ID, 'last_error_message', '');

		try {
			// Registers again and sends the first report right away.
			return new DataResponse(['status' => $this->registrationService->synchronise()]);
		} catch (\Throwable $e) {
			$this->registrationService->saveError($e);
			return new DataResponse(
				['status' => 'error', 'message' => $this->registrationService->describeFailure($e)],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}

	/**
	 * Stores the host of the current request, unless it is a loopback name or a
	 * value has already been detected.
	 */
	private function captureDomainFromRequest(): void {
		try {
			$host = $this->request->getServerHost();
			if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
				return;
			}

			$protocol = $this->request->getServerProtocol() ?: 'https';
			$this->itsConfig->setDetectedDomain($protocol . '://' . $host);
		} catch (\Throwable) {
			// Never let domain detection break the settings page.
		}
	}
}
