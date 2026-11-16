<?php

declare(strict_types=1);

namespace OCA\NCCare\Exception;

/**
 * The support backend answered with 403 `installation_expired`: the installation waited for approval for too long
 * without anybody creating an account for it, so its registration has expired.
 *
 * It is a state, not a failure to retry: the app stops reporting and tells the administrator to register the instance
 * again, which makes a new registration with a new identifier.
 *
 * @psalm-api
 */
class InstallationExpiredException extends \RuntimeException {

	public function __construct(?\Throwable $previous = null) {
		parent::__construct('The registration of this installation has expired.', 0, $previous);
	}
}
