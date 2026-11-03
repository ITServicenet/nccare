<?php

declare(strict_types=1);

namespace OCA\NCCare\Exception;

/**
 * The support backend answered a report with 401 or 403: it does not accept
 * this installation's token.
 *
 * It is a different failure from a registration that is refused, which concerns
 * `register()` and not the report. Keeping the two apart lets the app recover
 * from the first on its own and describe each one correctly.
 *
 * @psalm-api
 */
class InstallationRejectedException extends \RuntimeException {

	public function __construct(
		private readonly int $status,
		?\Throwable $previous = null,
	) {
		parent::__construct('The support backend rejected the installation token (HTTP ' . $status . ').', $status, $previous);
	}

	/** 401: token unknown or expired. 403: refused on purpose, do not work around. */
	public function getStatus(): int {
		return $this->status;
	}
}
