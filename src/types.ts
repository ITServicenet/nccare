export type CheckStatus = 'ok' | 'warn' | 'error' | 'unknown'

export interface Check {
	ok: boolean | null
	value: unknown
	status: CheckStatus
}

export type CheckMap = Record<string, Check>

export interface CheckSummary {
	ok: number
	warn: number
	error: number
	unknown: number
}

export interface ChecksResponse {
	checks: CheckMap
	summary: CheckSummary
}

/**
 * Status of the instance towards the support backend.
 *
 * No configuration travels here: the backend address, the reporting frequency
 * belong to the supported deployment and are set through occ config:app:set,
 * never from this interface.
 */
export interface Status {
	installation_id: string
	status: 'registered' | 'pending' | 'not_registered' | 'error' | 'expired'
	/**
	 * Whether a registration token is held. An instance whose registration
	 * failed reports status "error" while still needing to register, so the
	 * action offered must follow this flag rather than the status.
	 */
	registered: boolean
	/** Registered, but waiting for ITServicenet to approve it: only the first report goes out until then. */
	approval_pending: boolean
	/**
	 * Linked to an account on the portal. False invites the administrator to register; null means the backend has
	 * not said (unreachable or too old), and nothing is shown.
	 */
	account_linked: boolean | null
	/**
	 * When the registration expires if nobody creates an account for it (ISO 8601); empty when it does not, or is not
	 * told. An expired registration reports status "expired".
	 */
	approval_expires_at: string
	last_success_at: string
	last_error_at: string
	last_error_message: string
	app_version: string
	/** Sign-up page of the support portal, derived from the backend address. */
	register_url: string
	domain: string
	domain_resolved: boolean
}
