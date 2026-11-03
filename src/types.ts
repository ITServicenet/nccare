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
	status: 'registered' | 'pending' | 'not_registered' | 'error'
	/**
	 * Whether a registration token is held. An instance whose registration
	 * failed reports status "error" while still needing to register, so the
	 * action offered must follow this flag rather than the status.
	 */
	registered: boolean
	/** Registered, but waiting for ITServicenet to approve it: only the first report goes out until then. */
	approval_pending: boolean
	last_success_at: string
	last_error_at: string
	last_error_message: string
	app_version: string
	/** Sign-up page of the support portal, derived from the backend address. */
	register_url: string
	domain: string
	domain_resolved: boolean
}
