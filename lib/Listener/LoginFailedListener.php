<?php

declare(strict_types=1);

namespace OCA\NCCare\Listener;

use OCA\NCCare\Service\FailedLoginCounter;
use OCP\Authentication\Events\AnyLoginFailedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Feeds the failed login counter used by the brute force check.
 *
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class LoginFailedListener implements IEventListener {

	public function __construct(
		private readonly FailedLoginCounter $counter,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AnyLoginFailedEvent) {
			return;
		}

		try {
			$this->counter->record();
		} catch (\Throwable) {
			// Counting must never get in the way of a login attempt.
		}
	}
}
