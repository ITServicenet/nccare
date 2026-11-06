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
				/**
				 * @psalm-suppress UndefinedInterfaceMethod only present from Nextcloud 32, guarded by method_exists() above
				 * @psalm-suppress RedundantCast the cast keeps the analysis clean against the 30 and 31 stubs, where the method is unknown
				 */
				return (bool)$this->appManager->isEnabledForAnyone($appId);
			}

			/** @psalm-suppress DeprecatedMethod only reached on servers older than 32, which do not have the replacement */
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
			/**
			 * @psalm-suppress UndefinedInterfaceMethod only present on newer servers, guarded by method_exists() in the constructor
			 * @psalm-suppress DeprecatedMethod the fallback is only reached on servers older than 32
			 */
			$apps = $this->hasModernAppList
				? $this->appManager->getEnabledApps()
				: $this->appManager->getInstalledApps();

			/** @psalm-suppress DocblockTypeContradiction the stubs of newer servers type the result, older ones do not */
			if (!is_array($apps)) {
				return [];
			}

			/** @psalm-suppress RedundantConditionGivenDocblockType same reason as above */
			return array_values(array_filter($apps, 'is_string'));
		} catch (\Throwable) {
			return [];
		}
	}
}
