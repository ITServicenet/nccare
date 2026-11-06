<?php

declare(strict_types=1);

namespace OCA\NCCare\Config;

use OCA\NCCare\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Runtime application configuration.
 *
 * Values are stored in oc_appconfig and editable from the admin UI, with the
 * defaults below used as fallback. Only the app version stays a constant, as it
 * is tied to the released package rather than to the instance.
 *
 * @psalm-api
 */
class AppConfig {

	/**
	 * Current version of the NC Care app.
	 * Included in every report payload sent to the backend.
	 */
	public const string APP_VERSION = '1.0.5';

	/** Report payload schema version understood by the backend. */
	public const int REPORT_VERSION = 2;

	/**
	 * Where customers' instances report. It is built into the package so that
	 * nobody has to be told an address: build.sh replaces it for non-production
	 * packages (NCCARE_BACKEND_URL), and occ config:app:set overrides it for a
	 * supported deployment.
	 *
	 * @var string
	 */
	public const string DEFAULT_BACKEND_URL = 'https://nc-care.itservicenet.net';

	/**
	 * Reports are sent daily. Both this and the backend address are internal
	 * settings: they are deliberately absent from the admin interface, and can
	 * only be changed through occ config:app:set for a supported deployment.
	 *
	 * @var string
	 */
	public const string DEFAULT_SCHEDULE_FREQUENCY = 'daily';

	/** @var array<string, int> */
	public const array INTERVALS = [
		'daily' => 24 * 3600,
		'weekly' => 7 * 24 * 3600,
		'monthly' => 30 * 24 * 3600,
	];

	// Configuration keys stored in oc_appconfig.
	public const string KEY_BACKEND_URL = 'backend_url';
	public const string KEY_PORTAL_URL = 'portal_url';
	public const string KEY_SCHEDULE_FREQUENCY = 'schedule_frequency';
	public const string KEY_DEBUG_ENABLED = 'debug_enabled';
	public const string KEY_DETECTED_DOMAIN = 'detected_domain';
	public const string KEY_DOMAIN_OVERRIDE = 'domain_override';

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	public function getBackendUrl(): string {
		$value = $this->appConfig->getValueString(
			Application::APP_ID,
			self::KEY_BACKEND_URL,
			self::DEFAULT_BACKEND_URL,
		);
		return rtrim($value, '/');
	}

	/**
	 * Address of the portal as reached by a browser.
	 *
	 * In production this is the backend address itself. They differ only where
	 * the backend is reachable from the server under an internal name that a
	 * browser cannot resolve, as happens with container networking, in which
	 * case portal_url carries the public address.
	 */
	public function getPortalUrl(): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, self::KEY_PORTAL_URL, '');
		return $value !== '' ? rtrim($value, '/') : $this->getBackendUrl();
	}

	/**
	 * An unrecognised value falls back to the default, so a typo entered with
	 * occ config:app:set cannot leave the job without a valid interval.
	 */
	public function getScheduleFrequency(): string {
		$value = $this->appConfig->getValueString(
			Application::APP_ID,
			self::KEY_SCHEDULE_FREQUENCY,
			self::DEFAULT_SCHEDULE_FREQUENCY,
		);
		return isset(self::INTERVALS[$value]) ? $value : self::DEFAULT_SCHEDULE_FREQUENCY;
	}

	public function getScheduleIntervalSeconds(): int {
		return self::INTERVALS[$this->getScheduleFrequency()];
	}

	public function isDebugEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_DEBUG_ENABLED, false);
	}

	/**
	 * Domain detected from a real web request. The background job runs in CLI,
	 * where the request host is not available, so the value is captured when an
	 * administrator opens the settings page and reused afterwards.
	 */
	public function getDetectedDomain(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_DETECTED_DOMAIN, '');
	}

	public function setDetectedDomain(string $domain): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_DETECTED_DOMAIN, $domain);
	}

	/**
	 * Manual domain, taking precedence over any automatic detection. Set with
	 * occ config:app:set when the instance cannot determine its own address.
	 */
	public function getDomainOverride(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_DOMAIN_OVERRIDE, '');
	}
}
