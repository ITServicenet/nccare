<?php

declare(strict_types=1);

namespace OCA\NCCare\Service;

use OCA\NCCare\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager;

/**
 * Reminds the administrators, in the Nextcloud notification list, to create the account on the portal while the
 * instance has none. At most one reminder every few days, withdrawn as soon as the account exists or the
 * registration expires.
 */
class RegistrationReminder {
	/** Seconds between two reminders. */
	public const int INTERVAL = 3 * 86400;

	private const string KEY_LAST = 'registration_reminder_at';

	/** @psalm-suppress PossiblyUnusedMethod Built by the container */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IManager $notifications,
		private readonly IGroupManager $groups,
		private readonly ITimeFactory $time,
		private readonly RegistrationService $registration,
	) {
	}

	public function run(): void {
		// Same condition as the banner on the settings page: no account yet and the registration still valid. An
		// instance approved by hand without an account is in this case too.
		$waiting = $this->registration->isAccountLinked() === false && !$this->registration->isExpired();
		$last = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_LAST, 0);

		if (!$waiting) {
			if ($last > 0) {
				$this->withdraw();
				$this->appConfig->setValueInt(Application::APP_ID, self::KEY_LAST, 0);
			}
			return;
		}

		$now = $this->time->getTime();
		if ($last > 0 && $now - $last < self::INTERVAL) {
			return;
		}

		$this->withdraw();
		$group = $this->groups->get('admin');
		foreach ($group === null ? [] : $group->searchUsers('') as $user) {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($user->getUID())
				->setDateTime(new \DateTime('@' . $now))
				->setObject('registration', 'reminder')
				->setSubject('registration_reminder', ['link' => $this->registration->getRegisterUrl()]);
			$this->notifications->notify($notification);
		}
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_LAST, $now);
	}

	/** Removes the reminders still in the lists, so a new one does not pile up on an old one. */
	private function withdraw(): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)->setObject('registration', 'reminder');
		$this->notifications->markProcessed($notification);
	}
}
