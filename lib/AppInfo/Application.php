<?php

declare(strict_types=1);

namespace OCA\NCCare\AppInfo;

use OCA\NCCare\Listener\LoginFailedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Authentication\Events\AnyLoginFailedEvent;

class Application extends App implements IBootstrap {
	public const string APP_ID = 'nccare';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(AnyLoginFailedEvent::class, LoginFailedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
