import { translate as t } from '@nextcloud/l10n'

const APP_ID = 'nccare'

export interface CheckDescriptor {
	/** Human readable name of the check. */
	label: string
	/**
	 * What the result means and why it matters, per status. A key can carry the
	 * detected value ("warn:not_configured") when one status has distinct causes.
	 */
	messages: Record<string, string>
	/** How to put it right; shown when the check does not pass. */
	remedy?: string
}

/**
 * Catalogue of the automatic checks.
 *
 * Checks are keyed exactly as the backend reports them, and the wording is the
 * same one the backend shows in the customer portal (see lang/*\/checks.php in
 * nc-care-admin-backend): keep the two in step. An unknown key falls back to the
 * raw identifier so a newly added check still renders.
 */
export function describeCheck(key: string): CheckDescriptor {
	const catalog: Record<string, CheckDescriptor> = {
		audit_log: {
			label: t(APP_ID, 'Audit log'),
			messages: {
				ok: t(APP_ID, 'Administrative actions and sign-ins are recorded to a log file.'),
				warn: t(APP_ID, 'The audit app is on but no destination file is set, so records may not be kept.'),
				error: t(APP_ID, 'The audit log is off: there is no record of who did what, and many compliance requirements ask for it.'),
			},
			remedy: t(APP_ID, 'Enable the admin_audit app and set a destination log file.'),
		},
		cron: {
			label: t(APP_ID, 'System cron'),
			messages: {
				ok: t(APP_ID, 'Scheduled tasks run from the system cron, the most reliable mode.'),
				warn: t(APP_ID, 'Scheduled tasks are not run by the system cron, or the last run was more than 20 minutes ago, so they may start late.'),
				error: t(APP_ID, 'The system cron has not run for over an hour, or never has: scheduled tasks are piling up.'),
			},
			remedy: t(APP_ID, 'Start the cron service and set the mode to "Cron" in the basic settings.'),
		},
		cron_jobs_stale: {
			label: t(APP_ID, 'Background job backlog'),
			messages: {
				ok: t(APP_ID, 'Background jobs are being processed regularly.'),
				warn: t(APP_ID, 'Some jobs have not been picked up for over 24 hours: the queue is falling behind.'),
				error: t(APP_ID, 'Background jobs have been stuck for more than a week.'),
			},
			remedy: t(APP_ID, 'Check that cron is running and can reach Nextcloud.'),
		},
		notify_push: {
			label: t(APP_ID, 'Notify Push'),
			messages: {
				ok: t(APP_ID, 'Clients receive updates in real time.'),
				warn: t(APP_ID, 'Notify Push is not set up: clients poll the server at intervals, which adds load and delays notifications.'),
			},
			remedy: t(APP_ID, 'Install and configure the notify_push service.'),
		},
		twofa_enforced: {
			label: t(APP_ID, 'Two-factor authentication'),
			messages: {
				ok: t(APP_ID, 'Two-factor authentication is required for all users.'),
				warn: t(APP_ID, 'Two-factor authentication is required only for some groups.'),
				error: t(APP_ID, 'Two-factor authentication is not required: if a password is stolen, the account stays open to the thief.'),
			},
			remedy: t(APP_ID, 'Require it for all users, or at least for administrators.'),
		},
		trashbin: {
			label: t(APP_ID, 'File trash'),
			messages: {
				ok: t(APP_ID, 'The trash is on.'),
				error: t(APP_ID, 'The trash is off: files deleted by mistake cannot be recovered.'),
			},
			remedy: t(APP_ID, 'Enable the files_trashbin app.'),
		},
		versioning: {
			label: t(APP_ID, 'File versions'),
			messages: {
				ok: t(APP_ID, 'Previous versions of files are kept.'),
				error: t(APP_ID, 'Versions are not kept: a wrong edit cannot be undone.'),
			},
			remedy: t(APP_ID, 'Enable the files_versions app.'),
		},
		activity_app: {
			label: t(APP_ID, 'Activity log'),
			messages: {
				ok: t(APP_ID, 'The Activity app is on.'),
				error: t(APP_ID, 'The Activity app is off: users and administrators cannot see what happened on the instance.'),
			},
			remedy: t(APP_ID, 'Enable the Activity app.'),
		},
		brute_force_protection: {
			label: t(APP_ID, 'Blocked access management'),
			messages: {
				ok: t(APP_ID, 'The Brute-force settings app is on: you can manage allowed addresses and see blocks.'),
				warn: t(APP_ID, 'The Brute-force settings app is off: Nextcloud\'s limiter keeps working, but allowed addresses cannot be managed.'),
			},
			remedy: t(APP_ID, 'Enable the bruteforcesettings app.'),
		},
		brute_force_24h: {
			label: t(APP_ID, 'Failed sign-in attempts (24 hours)'),
			messages: {
				ok: t(APP_ID, 'No failed sign-ins in the last 24 hours.'),
				warn: t(APP_ID, 'Failed sign-ins were recorded in the last 24 hours: it may be a typing mistake or an attempt to guess a password.'),
				error: t(APP_ID, 'Many failed sign-ins were recorded in the last 24 hours: someone may be trying to get in without permission.'),
				unknown: t(APP_ID, 'Not verifiable yet: counting started recently and a full day of observation is needed before we can say there were no failed sign-ins.'),
			},
			remedy: t(APP_ID, 'Check the source addresses in the Nextcloud log and consider blocking them upstream (firewall or proxy).'),
		},
		password_policy: {
			label: t(APP_ID, 'Password rules'),
			messages: {
				ok: t(APP_ID, 'Password rules set adequate requirements.'),
				warn: t(APP_ID, 'Password rules are on but not very strict: a longer minimum and mixed characters are advisable.'),
				error: t(APP_ID, 'Password rules are off: users can choose weak passwords.'),
			},
			remedy: t(APP_ID, 'Raise the minimum length and require mixed character classes.'),
		},
		encryption: {
			label: t(APP_ID, 'Server-side encryption'),
			messages: {
				ok: t(APP_ID, 'Files are encrypted at rest.'),
				warn: t(APP_ID, 'Server-side encryption is off: it matters mostly when the storage is not under your control.'),
			},
			remedy: t(APP_ID, 'Consider it according to your compliance requirements.'),
		},
		links_no_expiry: {
			label: t(APP_ID, 'Public links without expiry'),
			messages: {
				ok: t(APP_ID, 'All public links have an expiry date.'),
				warn: t(APP_ID, 'Some public links have no expiry date.'),
				error: t(APP_ID, 'Many public links have no expiry date. Without a time limit on the shares created, files and folders may stay available when no longer needed.'),
			},
			remedy: t(APP_ID, 'Set a default expiry date on public links.'),
		},
		links_no_password: {
			label: t(APP_ID, 'Public links without password'),
			messages: {
				ok: t(APP_ID, 'All public links are password-protected.'),
				warn: t(APP_ID, 'Some public links have no password.'),
				error: t(APP_ID, 'Many public links have no password. Files and folders may be available without authentication. Always protecting shares with a password is recommended.'),
			},
			remedy: t(APP_ID, 'Require a password on public links.'),
		},
		share_password_enforced: {
			label: t(APP_ID, 'Mandatory password on links'),
			messages: {
				ok: t(APP_ID, 'A password is required on new public links.'),
				warn: t(APP_ID, 'Users can share without a password.'),
			},
			remedy: t(APP_ID, 'Turn on "Enforce password protection" in the sharing settings.'),
		},
		memcache_configured: {
			label: t(APP_ID, 'Memory cache'),
			messages: {
				ok: t(APP_ID, 'The memory cache is configured.'),
				warn: t(APP_ID, 'Distributed locking is missing: there is a local cache, but file locks are not shared.'),
				error: t(APP_ID, 'No memory cache: the instance is slower than it needs to be.'),
			},
			remedy: t(APP_ID, 'Set up APCu for the local cache and Redis for locking.'),
		},
		debug_disabled: {
			label: t(APP_ID, 'Debug mode'),
			messages: {
				ok: t(APP_ID, 'Debug mode is off.'),
				error: t(APP_ID, 'Debug mode is on: messages show internal details that are only useful during development.'),
			},
			remedy: t(APP_ID, 'Set debug to false in config.php.'),
		},
		opcache_enabled: {
			label: t(APP_ID, 'PHP OPcache'),
			messages: {
				ok: t(APP_ID, 'OPcache is on.'),
				warn: t(APP_ID, 'OPcache is off: PHP responds more slowly.'),
			},
			remedy: t(APP_ID, 'Enable opcache.enable in the PHP configuration.'),
		},
		onlyoffice_builtin: {
			label: t(APP_ID, 'OnlyOffice'),
			messages: {
				ok: t(APP_ID, 'OnlyOffice uses a dedicated document server.'),
				warn: t(APP_ID, 'OnlyOffice uses the built-in endpoint, meant for evaluation rather than production load.'),
				'warn:not_configured': t(APP_ID, 'The OnlyOffice app is installed but no server is configured, so documents cannot be opened.'),
			},
			remedy: t(APP_ID, 'Set up a dedicated document server.'),
		},
		collabora_builtin: {
			label: t(APP_ID, 'Collabora'),
			messages: {
				ok: t(APP_ID, 'Collabora uses a dedicated server.'),
				warn: t(APP_ID, 'Collabora uses the built-in engine, which handles only a few users.'),
				'warn:not_configured': t(APP_ID, 'The Collabora app is installed but no server is configured, so documents cannot be opened.'),
			},
			remedy: t(APP_ID, 'Set up Collabora on a dedicated server.'),
		},
		talk_turn_configured: {
			label: t(APP_ID, 'Talk: TURN/STUN servers'),
			messages: {
				ok: t(APP_ID, 'TURN/STUN servers are configured.'),
				warn: t(APP_ID, 'TURN/STUN servers are not configured: calls between participants behind NAT may fail.'),
			},
			remedy: t(APP_ID, 'Set them up in the Talk settings.'),
		},
	}

	return catalog[key] ?? { label: key, messages: {} }
}

/**
 * The sentence describing a check's result: the variant for the detected value
 * when there is one, then the one for the status, then a generic text.
 */
export function checkMessage(descriptor: CheckDescriptor, status: string, value: unknown): string {
	const variant = typeof value === 'string' ? descriptor.messages[`${status}:${value}`] : undefined
	return variant
		?? descriptor.messages[status]
		?? t(APP_ID, 'This check could not be verified on this instance.')
}
