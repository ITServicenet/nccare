<?php

declare(strict_types=1);

namespace OCA\NCCare\Exception;

/**
 * The support backend answered a report with 403 `installation_pending`: the installation registered and sent its
 * first report, and now waits for ITServicenet to approve it.
 *
 * It is not a failure: nothing is wrong and nothing for the administrator to fix, so it is kept apart from
 * InstallationRejectedException and is not recorded as an error.
 *
 * @psalm-api
 */
class InstallationPendingException extends \RuntimeException {

	public function __construct(?\Throwable $previous = null) {
		parent::__construct('The installation is waiting for approval.', 0, $previous);
	}
}
