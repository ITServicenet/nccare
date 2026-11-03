<?php

declare(strict_types=1);

namespace OCA\NCCare\Compat;

use OCP\App\IAppManager;

/**
 * Reports which apps are enabled, across the supported Nextcloud versions.
 *
 * Nextcloud 32 introduced isEnabledForAnyone() and getEnabledApps(), and at the
 * same time deprecated isInstalled() and getInstalledApps(). Probing for the
 * newer methods keeps the app working on 30 through 34 without emitting
 * deprecation notices on recent servers.
 *
 * @psalm-api
 */
class AppAvailability {

	private readonly bool $hasModernEnabledCheck;
	private readonly bool $hasModernAppList;

	public function __construct(
		private readonly IAppManager $appManager,
	) {
		$this->hasModernEnabledCheck = method_exists($this->appManager, 'isEnabledForAnyone');
		$this->hasModernAppList = method_exists($this->appManager, 'getEnabledApps');
	}

	public function isEnabled(string $appId): bool {
		try {
			if ($this->hasModernEnabledCheck) {
				/** @psalm-suppress UndefinedInterfaceMethod only present from Nextcloud 32, guarded by method_exists() above */
				return (bool)$this->appManager->isEnabledForAnyone($appId);
			}

			return $this->appManager->isInstalled($appId);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Identifiers of every enabled app.
	 *
	 * @return list<string>
	 */
	public function getEnabledApps(): array {
		try {
			/** @psalm-suppress UndefinedInterfaceMethod only present on newer servers, guarded by method_exists() in the constructor */
			$apps = $this->hasModernAppList
				? $this->appManager->getEnabledApps()
				: $this->appManager->getInstalledApps();

			if (!is_array($apps)) {
				return [];
			}

			return array_values(array_filter($apps, 'is_string'));
		} catch (\Throwable) {
			return [];
		}
	}
}
