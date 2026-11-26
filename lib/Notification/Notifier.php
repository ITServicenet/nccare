<?php

declare(strict_types=1);

namespace OCA\NCCare\Notification;

use OCA\NCCare\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** Shows the reminder to create the portal account in the Nextcloud notification list. */
class Notifier implements INotifier {
	/** @psalm-suppress PossiblyUnusedMethod Built by the container */
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('NC Care');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== 'registration_reminder') {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		/** @psalm-suppress MixedAssignment the parameters are plain JSON values, checked on the next line */
		$value = $params['link'] ?? null;
		$link = is_string($value) ? $value : '';

		$notification->setParsedSubject($l->t('Finish registering this instance on NC Care'));
		$notification->setParsedMessage($l->t('Reports are being sent, but nobody has created an account on the NC Care portal yet.'));
		if ($link !== '') {
			$notification->setLink($link);
		}

		return $notification;
	}
}
