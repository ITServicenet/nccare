<?php

declare(strict_types=1);

namespace OCA\NCCare\Settings;

use OCA\NCCare\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/** @psalm-api */
class AdminSection implements IIconSection {

	public function __construct(
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'NC Care';
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
