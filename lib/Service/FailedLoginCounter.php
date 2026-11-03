<?php

declare(strict_types=1);

namespace OCA\NCCare\Service;

use OCA\NCCare\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Counts failed logins over a rolling 24 hour window.
 *
 * Nextcloud keeps its own brute force attempts in the database only when no
 * distributed cache is configured. With Redis or APCu they live in the cache,
 * hashed per subnet, and cannot be totalled. Counting the failed login events
 * ourselves gives the same answer on every installation.
 *
 * The count is kept in hourly buckets inside a single app config value, so no
 * table or migration is needed and the value never grows beyond a day of data.
 *
 * @psalm-api
 */
class FailedLoginCounter {

	public const string KEY = 'failed_logins';
	private const int BUCKET_SECONDS = 3600;
	private const int WINDOW_SECONDS = 24 * 3600;

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
	) {
	}

	/** Records one failed login at the current time. */
	public function record(): void {
		$now = $this->time->getTime();
		$state = $this->load($now);

		$bucket = (string)intdiv($now, self::BUCKET_SECONDS);
		$state['buckets'][$bucket] = ($state['buckets'][$bucket] ?? 0) + 1;

		$this->save($state);
	}

	/** Number of failed logins in the last 24 hours. */
	public function countLast24Hours(): int {
		$state = $this->load($this->time->getTime());
		return array_sum($state['buckets']);
	}

	/**
	 * Whether a full 24 hours have passed since counting started. Before that a
	 * count of zero only means "nothing seen yet", not "nothing happened".
	 */
	public function hasFullWindow(): bool {
		$now = $this->time->getTime();
		$state = $this->load($now);
		if ($state['since'] === 0) {
			// First look: start the clock so the window can fill up.
			$state['since'] = $now;
			$this->save($state);
			return false;
		}

		return $now - $state['since'] >= self::WINDOW_SECONDS;
	}

	/**
	 * @return array{since: int, buckets: array<string, int>}
	 */
	private function load(int $now): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY, '');
		/** @var array{since?: int|string, buckets?: array<array-key, int|string>}|null $data */
		$data = $raw === '' ? null : json_decode($raw, true);
		$data = is_array($data) ? $data : [];

		$oldest = intdiv($now - self::WINDOW_SECONDS, self::BUCKET_SECONDS);
		$buckets = [];
		foreach ($data['buckets'] ?? [] as $bucket => $count) {
			if ((int)$bucket > $oldest && (int)$count > 0) {
				$buckets[(string)$bucket] = (int)$count;
			}
		}

		return ['since' => (int)($data['since'] ?? 0), 'buckets' => $buckets];
	}

	/** @param array{since: int, buckets: array<string, int>} $state */
	private function save(array $state): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY, json_encode($state, JSON_THROW_ON_ERROR));
	}
}
