<?php

declare(strict_types=1);

namespace OCA\NCCare\Service;

use OCA\NCCare\Compat\AppAvailability;
use OCP\IConfig;
use OCP\IServerContainer;
use Psr\Log\LoggerInterface;

/**
 * Reads hardware and platform statistics exposed by the serverinfo app.
 *
 * The app is optional: when it is missing, disabled or its classes cannot be
 * autoloaded, every getter degrades to null and the report is still sent.
 *
 * serverinfo is a separate app with no published types, so everything it
 * returns is treated as untrusted: each payload goes through arrayOf() before
 * any key is read, and a value of an unexpected shape becomes null instead of
 * an error.
 *
 * @psalm-api
 * @psalm-suppress DeprecatedInterface IServerContainer is what serverinfo's own services are resolved from;
 *                 the PSR container alias is not available on every supported release.
 */
class ServerInfoService {

	private const string SYSTEM_STATISTICS = '\OCA\ServerInfo\SystemStatistics';
	private const string DATABASE_STATISTICS = '\OCA\ServerInfo\DatabaseStatistics';
	private const string SHARE_STATISTICS = '\OCA\ServerInfo\ShareStatistics';
	private const string STORAGE_STATISTICS = '\OCA\ServerInfo\StorageStatistics';
	private const string PHP_STATISTICS = '\OCA\ServerInfo\PhpStatistics';
	private const string OS_RESOURCE = '\OCA\ServerInfo\Os';

	public function __construct(
		private readonly AppAvailability $apps,
		private readonly IServerContainer $container,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		return $this->apps->isEnabled('serverinfo')
			&& class_exists(self::SYSTEM_STATISTICS);
	}

	/**
	 * Hardware and platform statistics, or null when serverinfo is unavailable.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getServerInfo(): ?array {
		if (!$this->isAvailable()) {
			return null;
		}

		return [
			'system' => $this->getSystemStatistics(),
			'database' => $this->getDatabaseStatistics(),
			'shares' => $this->getShareStatistics(),
			'storage' => $this->getStorageStatistics(),
			'php' => $this->getPhpStatistics(),
			'os' => $this->getOsStatistics(),
			'data_disk' => $this->getDataDisk(),
		];
	}

	/**
	 * Total, used and free space of the filesystem holding the data directory.
	 *
	 * serverinfo lists disks by parsing `df` and skips overlay/tmpfs, so inside a
	 * container (and always with rootless Docker or Podman) it reports none. The
	 * PHP filesystem calls need no shell and no privileges, and work wherever the
	 * data directory is readable, so this figure is always available.
	 *
	 * @return array{total_mb: int, used_mb: int, free_mb: int}|null
	 */
	public function getDataDisk(): ?array {
		try {
			$directory = $this->config->getSystemValueString('datadirectory', '');
			if ($directory === '' || !is_dir($directory)) {
				return null;
			}
			$total = @disk_total_space($directory);
			$free = @disk_free_space($directory);
		} catch (\Throwable) {
			return null;
		}
		if ($total === false || $free === false || $total <= 0) {
			return null;
		}

		$totalMb = (int)floor($total / 1048576);
		$freeMb = (int)floor(min($free, $total) / 1048576);

		return [
			'total_mb' => $totalMb,
			'used_mb' => max(0, $totalMb - $freeMb),
			'free_mb' => $freeMb,
		];
	}

	/** @return array<string, mixed>|null */
	private function getSystemStatistics(): ?array {
		return $this->safely('system', function (): ?array {
			$stats = $this->resolve(self::SYSTEM_STATISTICS);
			if ($stats === null || !method_exists($stats, 'getSystemStatistics')) {
				return null;
			}
			$data = $this->arrayOf($stats->getSystemStatistics());
			$apps = $this->arrayOf($data['apps'] ?? null);

			return [
				'cpu_load' => $data['cpuload'] ?? null,
				'cpu_num' => $data['cpunum'] ?? null,
				'mem_total_kb' => $data['mem_total'] ?? null,
				'mem_free_kb' => $data['mem_free'] ?? null,
				'swap_total_kb' => $data['swap_total'] ?? null,
				'swap_free_kb' => $data['swap_free'] ?? null,
				'freespace_bytes' => $data['freespace'] ?? null,
				'apps_installed' => $apps['num_installed'] ?? null,
				'apps_updates_available' => $apps['num_updates_available'] ?? null,
				'app_updates' => $apps['app_updates'] ?? [],
			];
		});
	}

	/** @return array<string, mixed>|null */
	private function getDatabaseStatistics(): ?array {
		return $this->safely('database', function (): ?array {
			$stats = $this->resolve(self::DATABASE_STATISTICS);
			if ($stats === null || !method_exists($stats, 'getDatabaseStatistics')) {
				return null;
			}
			$data = $this->arrayOf($stats->getDatabaseStatistics());

			return [
				'type' => $data['type'] ?? null,
				'version' => $data['version'] ?? null,
				'size_bytes' => isset($data['size']) && is_numeric($data['size']) ? (int)$data['size'] : null,
			];
		});
	}

	/** @return array<string, mixed>|null */
	private function getShareStatistics(): ?array {
		return $this->safely('shares', function (): ?array {
			$stats = $this->resolve(self::SHARE_STATISTICS);
			if ($stats === null || !method_exists($stats, 'getShareStatistics')) {
				return null;
			}
			$data = $this->arrayOf($stats->getShareStatistics());

			return [
				'total' => $data['num_shares'] ?? null,
				'user' => $data['num_shares_user'] ?? null,
				'group' => $data['num_shares_groups'] ?? null,
				'link' => $data['num_shares_link'] ?? null,
				'mail' => $data['num_shares_mail'] ?? null,
				'room' => $data['num_shares_room'] ?? null,
				'link_no_password' => $data['num_shares_link_no_password'] ?? null,
				'federated_sent' => $data['num_fed_shares_sent'] ?? null,
				'federated_received' => $data['num_fed_shares_received'] ?? null,
			];
		});
	}

	/** @return array<string, mixed>|null */
	private function getStorageStatistics(): ?array {
		return $this->safely('storage', function (): ?array {
			$stats = $this->resolve(self::STORAGE_STATISTICS);
			if ($stats === null || !method_exists($stats, 'getStorageStatistics')) {
				return null;
			}
			$data = $this->arrayOf($stats->getStorageStatistics());

			return [
				'num_files' => $data['num_files'] ?? null,
				'num_storages' => $data['num_storages'] ?? null,
				'num_storages_local' => $data['num_storages_local'] ?? null,
				'num_storages_home' => $data['num_storages_home'] ?? null,
				'num_storages_other' => $data['num_storages_other'] ?? null,
			];
		});
	}

	/**
	 * @return array<string, mixed>|null
	 * @psalm-suppress MixedAssignment values come from the serverinfo app, which publishes no types
	 */
	private function getPhpStatistics(): ?array {
		return $this->safely('php', function (): ?array {
			$stats = $this->resolve(self::PHP_STATISTICS);
			if ($stats === null || !method_exists($stats, 'getPhpStatistics')) {
				return null;
			}
			$data = $this->arrayOf($stats->getPhpStatistics());

			// The full extension list is verbose and of little diagnostic value;
			// only the count travels to the backend.
			$extensions = $data['extensions'] ?? [];

			return [
				'version' => $data['version'] ?? null,
				'memory_limit' => $data['memory_limit'] ?? null,
				'max_execution_time' => $data['max_execution_time'] ?? null,
				'upload_max_filesize' => $data['upload_max_filesize'] ?? null,
				'opcache_active' => !empty($data['opcache']),
				'apcu_active' => !empty($data['apcu']),
				'extensions_count' => is_array($extensions) ? count($extensions) : null,
			];
		});
	}

	/**
	 * Memory and disk are returned as objects by serverinfo and have to be read
	 * through their getters; serialising them directly yields empty structures.
	 *
	 * @return array<string, mixed>|null
	 * @psalm-suppress MixedAssignment values come from the serverinfo app, which publishes no types
	 */
	private function getOsStatistics(): ?array {
		return $this->safely('os', function (): ?array {
			if (!class_exists(self::OS_RESOURCE)) {
				return null;
			}
			$os = $this->resolve(self::OS_RESOURCE);
			if ($os === null) {
				return null;
			}

			$result = [];

			if (method_exists($os, 'getOSName')) {
				$result['os_name'] = $this->callGetter($os, 'getOSName');
			}
			if (method_exists($os, 'getUptime')) {
				$result['uptime_seconds'] = $this->callGetter($os, 'getUptime');
			}

			if (method_exists($os, 'getMemory')) {
				$memory = $this->callGetter($os, 'getMemory');
				if (is_object($memory)) {
					$result['mem_total_mb'] = $this->callGetter($memory, 'getMemTotal');
					$result['mem_free_mb'] = $this->callGetter($memory, 'getMemFree');
					$result['mem_available_mb'] = $this->callGetter($memory, 'getMemAvailable');
					$result['swap_total_mb'] = $this->callGetter($memory, 'getSwapTotal');
					$result['swap_free_mb'] = $this->callGetter($memory, 'getSwapFree');
				}
			}

			if (method_exists($os, 'getDiskInfo')) {
				$disks = $this->callGetter($os, 'getDiskInfo');
				if (is_array($disks)) {
					$list = [];
					foreach ($disks as $disk) {
						if (!is_object($disk)) {
							continue;
						}
						$list[] = [
							'mount' => $this->callGetter($disk, 'getMount'),
							'device' => $this->callGetter($disk, 'getDevice'),
							'used_mb' => $this->callGetter($disk, 'getUsed'),
							'available_mb' => $this->callGetter($disk, 'getAvailable'),
							'percent' => $this->callGetter($disk, 'getPercent'),
						];
					}
					$result['disks'] = $list;
				}
			}

			return $result;
		});
	}

	/**
	 * Only an array is trusted; anything else (null, scalar, object) becomes an
	 * empty array so that every key read below simply yields null.
	 *
	 * @return array<array-key, mixed>
	 */
	private function arrayOf(mixed $value): array {
		return is_array($value) ? $value : [];
	}

	/**
	 * Calls a getter of a serverinfo object, returning null when it does not
	 * exist or throws. The return value is whatever serverinfo produces, hence mixed.
	 */
	private function callGetter(object $object, string $method): mixed {
		if (!method_exists($object, $method)) {
			return null;
		}
		try {
			return $object->$method();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Resolves a serverinfo class from the container, returning null instead of
	 * throwing when the service is not registered.
	 *
	 * @psalm-suppress MixedAssignment the container returns mixed; it is narrowed to an object right below
	 */
	private function resolve(string $className): ?object {
		if (!class_exists($className)) {
			return null;
		}
		try {
			$service = $this->container->get($className);

			return is_object($service) ? $service : null;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'NC Care:cannot resolve ' . $className . ': ' . $e->getMessage(),
				['app' => 'nccare'],
			);
			return null;
		}
	}

	/**
	 * @param callable():(array<string, mixed>|null) $callback
	 * @return array<string, mixed>|null
	 */
	private function safely(string $section, callable $callback): ?array {
		try {
			return $callback();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'NC Care:serverinfo section "' . $section . '" failed: ' . $e->getMessage(),
				['app' => 'nccare', 'exception' => $e],
			);
			return null;
		}
	}
}
