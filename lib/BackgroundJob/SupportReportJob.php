<?php

declare(strict_types=1);

namespace OCA\NCCare\BackgroundJob;

use OCA\NCCare\AppInfo\Application;
use OCA\NCCare\Config\AppConfig;
use OCA\NCCare\Service\RegistrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** @psalm-api */
class SupportReportJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private readonly RegistrationService $registrationService,
		AppConfig $itsConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval($itsConfig->getScheduleIntervalSeconds());
	}

	protected function run(mixed $argument): void {
		try {
			// Registers when needed and sends the report; if the backend no longer
			// knows the token it registers again on its own (see synchronise()).
			$result = $this->registrationService->synchronise();
			$this->logger->info(
				match ($result) {
					'registered' => 'NC Care:registration successful',
					'pending' => 'NC Care:waiting for approval, report not sent',
					'busy' => 'NC Care:another registration or report is already running, skipped',
					default => 'NC Care:report sent successfully',
				},
				['app' => Application::APP_ID],
			);
		} catch (\Throwable $e) {
			$this->registrationService->saveError($e);
			$this->logger->error('NC Care:background job failed: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}
}
