<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import SecurityComplianceReview from '../components/SecurityComplianceReview.vue'
import type { ChecksResponse, Status } from '../types'

const APP_ID = 'nccare'
const APP_NAME = 'NC Care'
const CONTACT_URL = 'https://itservicenet.net/contattaci/'
const LANDING_URL = 'https://nextcloud-italia.it'
const SCAN_URL = 'https://scan.nextcloud.com'
const HEADERS_URL = 'https://securityheaders.com'

function ocs(path: string): string {
	return generateOcsUrl(`apps/${APP_ID}/${path}`)
}

// ─── Notifications ──────────────────────────────────────────────────────────
const notification = ref<{ type: 'success' | 'error', message: string } | null>(null)
let notificationTimer: ReturnType<typeof setTimeout> | null = null

function notify(type: 'success' | 'error', message: string) {
	if (notificationTimer) clearTimeout(notificationTimer)
	notification.value = { type, message }
	notificationTimer = setTimeout(() => { notification.value = null }, type === 'error' ? 6000 : 4000)
}

function errorMessage(e: unknown, fallback: string): string {
	const response = (e as { response?: { data?: { ocs?: { data?: { message?: string } } } } })?.response
	return response?.data?.ocs?.data?.message ?? fallback
}

// ─── State ──────────────────────────────────────────────────────────────────
const status = ref<Status>({
	installation_id: '',
	status: 'not_registered',
	registered: false,
	approval_pending: false,
	last_success_at: '',
	last_error_at: '',
	last_error_message: '',
	app_version: '',
	register_url: '',
	domain: '',
	domain_resolved: false,
})

const review = ref<ChecksResponse>({
	checks: {},
	summary: { ok: 0, warn: 0, error: 0, unknown: 0 },
})

const loading = ref(true)
const loadingChecks = ref(true)
const running = ref(false)
const testing = ref(false)
const resetting = ref(false)
const showResetDialog = ref(false)

/**
 * Outcome of the last connection test and of the last report, shown beside the
 * registration badge. The buttons that trigger them sit at the bottom of the
 * section, where a banner at the top of the page would go unnoticed.
 */
const connectionResult = ref<'reachable' | 'unreachable' | null>(null)
const reportResult = ref<'sent' | 'registered' | 'pending' | 'busy' | 'failed' | 'registration_failed' | null>(null)

const statusLabel = computed<Record<string, string>>(() => ({
	registered: t(APP_ID, 'Registered'),
	pending: t(APP_ID, 'Awaiting approval'),
	not_registered: t(APP_ID, 'Not registered'),
	error: t(APP_ID, 'Error'),
}))

const statusVariant = computed(() => ({
	registered: 'ok',
	pending: 'warn',
	not_registered: 'warn',
	error: 'error',
}[status.value.status] ?? 'warn'))

// ─── Loading ────────────────────────────────────────────────────────────────
/**
 * Refreshing after an action is silent on purpose: raising the loading flag
 * would unmount every section to show the spinner, and the page would jump back
 * to the top once they are rebuilt. Only the first load blanks the page.
 */
async function loadStatus({ silent = false }: { silent?: boolean } = {}) {
	if (!silent) {
		loading.value = true
	}
	try {
		const res = await axios.get(ocs('admin/settings'))
		status.value = { ...status.value, ...res.data.ocs.data }
	} catch {
		notify('error', t(APP_ID, 'Could not load the current status.'))
	} finally {
		loading.value = false
	}
}

async function loadChecks() {
	loadingChecks.value = true
	try {
		const res = await axios.get(ocs('admin/checks'))
		review.value = res.data.ocs.data
	} catch {
		notify('error', t(APP_ID, 'Could not run the security checks.'))
	} finally {
		loadingChecks.value = false
	}
}

// ─── Actions ────────────────────────────────────────────────────────────────
async function runNow() {
	// The same button registers the instance or sends a report, so the outcome
	// has to be described in terms of whatever was actually attempted.
	const wasRegistering = !status.value.registered
	running.value = true
	reportResult.value = null
	try {
		const res = await axios.post(ocs('admin/run-now'))
		const outcome = res.data.ocs.data.status
		reportResult.value = ['registered', 'pending', 'busy'].includes(outcome) ? outcome : 'sent'
	} catch (e) {
		reportResult.value = wasRegistering ? 'registration_failed' : 'failed'
		// The chip alone does not say why; the reason belongs in the banner and
		// is also persisted as the last error just below.
		notify('error', errorMessage(e, wasRegistering
			? t(APP_ID, 'The instance could not be registered.')
			: t(APP_ID, 'The report could not be sent.')))
	} finally {
		running.value = false
		await loadStatus({ silent: true })
	}
}

/**
 * The outcome is kept beside the registration badge rather than only in the
 * banner at the top of the page, which scrolls out of view.
 */
async function testConnection() {
	testing.value = true
	connectionResult.value = null
	try {
		const res = await axios.get(ocs('admin/test-connection'))
		connectionResult.value = res.data.ocs.data.success ? 'reachable' : 'unreachable'
	} catch {
		connectionResult.value = 'unreachable'
	} finally {
		testing.value = false
	}
}

async function confirmReset() {
	showResetDialog.value = false
	resetting.value = true
	try {
		const res = await axios.post(ocs('admin/retry-registration'))
		notify('success', res.data.ocs.data.status === 'registered'
			? t(APP_ID, 'Registration reset. The instance registered again with a new identifier.')
			: t(APP_ID, 'Registration reset. A new identifier will be generated on the next report.'))
	} catch (e) {
		notify('error', t(APP_ID, 'Reset done locally, but registration failed: {reason}', {
			reason: errorMessage(e, t(APP_ID, 'unknown error')),
		}))
	} finally {
		resetting.value = false
		await loadStatus({ silent: true })
	}
}

onMounted(() => {
	loadStatus()
	loadChecks()
})
</script>

<template>
	<div class="its">
		<NcNoteCard v-if="notification" :type="notification.type" class="its__banner">
			{{ notification.message }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="64" />

		<template v-else>
			<!-- ── 1. THE APP ────────────────────────────────────────────── -->
			<NcSettingsSection :name="APP_NAME"
				:description="t('nccare', 'Nextcloud Care - Controlla. Proteggi. Migliora.')">
				<p class="its__paragraph">
					{{ t('nccare', 'Health monitoring and security review for this Nextcloud installation.') }}
				</p>
				<p class="its__paragraph">
					{{ t('nccare', 'This app watches over the installation on your behalf. It runs a set of automatic checks on the configuration, from backups and audit logging to two-factor authentication and public link policies, and reports the outcome to the ITServicenet support backend.') }}
				</p>
				<p class="its__paragraph">
					{{ t('nccare', 'Our engineers see when something needs attention, often before it becomes a problem for your users. The app never changes any setting: it only observes and reports.') }}
				</p>
			</NcSettingsSection>

			<!-- ── 2. PORTAL ─────────────────────────────────────────────── -->
			<NcSettingsSection :name="t('nccare', 'ITServicenet portal')"
				:description="t('nccare', 'Your account on the support portal.')">
				<p class="its__paragraph">
					{{ t('nccare', 'Create an account on the ITServicenet portal to follow the health of your instance over time, receive an alert when it becomes unreachable or when a check starts failing, consult the history of the automatic reviews and reach certified Nextcloud engineers when you need help.') }}
				</p>
				<p class="its__paragraph">
					{{ t('nccare', 'From the portal you also manage which apps are monitored, where alerts are delivered and the compliance questionnaire for practices that cannot be verified automatically.') }}
				</p>
				<NcButton v-if="status.register_url"
					type="primary"
					:href="status.register_url"
					target="_blank">
					{{ t('nccare', 'Create an account') }}
				</NcButton>
			</NcSettingsSection>

			<!-- ── 3. WHAT THIS APP COLLECTS ─────────────────────────────── -->
			<NcSettingsSection :name="t('nccare', 'What this app collects')"
				:description="t('nccare', 'Transparency about the data leaving this instance.')">
				<p class="its__paragraph">
					{{ t('nccare', 'Each report includes the Nextcloud and PHP versions, the database type, aggregate user counts, the list of enabled apps, the background job mode, the results of the automatic checks below and, when available, hardware statistics.') }}
				</p>

				<NcNoteCard type="info">
					<strong>{{ t('nccare', 'No file contents, file names or personal data are ever transmitted.') }}</strong>
				</NcNoteCard>

				<p class="its__paragraph">
					{{ t('nccare', 'Reports are sent daily by the Nextcloud background job. System cron is required for them to run on schedule.') }}
				</p>
			</NcSettingsSection>

			<!-- ── 4. REGISTRATION STATUS ────────────────────────────────── -->
			<NcSettingsSection :name="t('nccare', 'Registration status')"
				:description="t('nccare', 'Current registration status of this Nextcloud instance with NC Care.')">
				<div class="chips">
					<span class="chip" :class="`chip--${statusVariant}`">
						<span class="chip__dot" />
						{{ statusLabel[status.status] }}
					</span>

					<span v-if="testing" class="chip chip--neutral">
						<NcLoadingIcon :size="14" />
						{{ t('nccare', 'Testing…') }}
					</span>

					<span v-else-if="connectionResult"
						class="chip"
						:class="connectionResult === 'reachable' ? 'chip--ok' : 'chip--error'">
						<span class="chip__dot" />
						{{ connectionResult === 'reachable'
							? t('nccare', 'Backend reachable')
							: t('nccare', 'Backend unreachable') }}
					</span>

					<span v-if="running" class="chip chip--neutral">
						<NcLoadingIcon :size="14" />
						{{ t('nccare', 'Sending…') }}
					</span>

					<span v-else-if="reportResult"
						class="chip"
						:class="reportResult === 'sent' || reportResult === 'registered' ? 'chip--ok' : (reportResult === 'pending' || reportResult === 'busy' ? 'chip--warn' : 'chip--error')">
						<span class="chip__dot" />
						{{ {
							sent: t('nccare', 'Report sent'),
							registered: t('nccare', 'Instance registered'),
							pending: t('nccare', 'Awaiting approval'),
							busy: t('nccare', 'Already in progress'),
							failed: t('nccare', 'Report not sent'),
							registration_failed: t('nccare', 'Registration failed'),
						}[reportResult] }}
					</span>
				</div>

				<NcNoteCard v-if="!status.domain_resolved" type="warning">
					{{ t('nccare', 'The address of this instance could not be determined, so support cannot check whether it is reachable. Set overwrite.cli.url in config.php.') }}
				</NcNoteCard>

				<dl class="its__info">
					<dt>{{ t('nccare', 'Installation ID') }}</dt>
					<dd>{{ status.installation_id || t('nccare', 'not generated yet') }}</dd>

					<dt>{{ t('nccare', 'Instance address') }}</dt>
					<dd>{{ status.domain || t('nccare', 'not determined') }}</dd>

					<template v-if="status.last_success_at">
						<dt>{{ t('nccare', 'Last report') }}</dt>
						<dd>{{ status.last_success_at }}</dd>
					</template>

					<template v-if="status.last_error_at">
						<dt>{{ t('nccare', 'Last error') }}</dt>
						<dd class="its__error">
							{{ status.last_error_at }}
						</dd>
					</template>
				</dl>

				<NcNoteCard v-if="status.last_error_message" type="error">
					{{ status.last_error_message }}
				</NcNoteCard>

				<NcNoteCard v-if="status.approval_pending" type="info">
					<p>
						{{ t('nccare', 'This instance is waiting for approval by ITServicenet, which has been notified. The first report has been sent; the next ones go out as soon as it is approved. Nothing else is needed.') }}
					</p>
				</NcNoteCard>

				<NcNoteCard v-if="!status.registered" type="warning">
					<p>
						{{ t('nccare', 'This instance is not registered yet, so no report reaches support. Registration is retried automatically by the daily background job; use the button below to try again right away.') }}
					</p>
				</NcNoteCard>

				<div class="its__actions">
					<!--
						The label follows the registration flag, not the status: an
						instance whose registration failed still has to register, and
						offering to send a report would be misleading.
					-->
					<NcButton type="primary" :disabled="running" @click="runNow">
						<template #icon>
							<NcLoadingIcon v-if="running" :size="20" />
						</template>
						{{ status.registered
							? t('nccare', 'Send report now')
							: t('nccare', 'Register this instance') }}
					</NcButton>

					<NcButton type="secondary" :disabled="testing" @click="testConnection">
						<template #icon>
							<NcLoadingIcon v-if="testing" :size="20" />
						</template>
						{{ t('nccare', 'Test connection') }}
					</NcButton>

					<NcButton type="error" :disabled="resetting" @click="showResetDialog = true">
						<template #icon>
							<NcLoadingIcon v-if="resetting" :size="20" />
						</template>
						{{ t('nccare', 'Reset registration') }}
					</NcButton>
				</div>
			</NcSettingsSection>

			<!-- ── 5. SECURITY & COMPLIANCE REVIEW ───────────────────────── -->
			<NcSettingsSection :name="t('nccare', 'Security & Compliance Review')"
				:description="t('nccare', 'Everything that can be verified automatically on this instance. Nothing here modifies the system.')">
				<NcLoadingIcon v-if="loadingChecks" :size="44" />
				<SecurityComplianceReview v-else
					:checks="review.checks"
					:summary="review.summary" />
			</NcSettingsSection>

			<!-- ── 6. SERVICES ───────────────────────────────────────────── -->
			<NcSettingsSection :name="t('nccare', 'ITServicenet services')"
				:description="t('nccare', 'How we can help with this installation.')">
				<ul class="its__services">
					<li>{{ t('nccare', 'Certified Nextcloud stack, deployed and maintained') }}</li>
					<li>{{ t('nccare', 'Enterprise support with ticketing and agreed response times') }}</li>
					<li>{{ t('nccare', 'Log analysis and proactive monitoring') }}</li>
					<li>{{ t('nccare', 'NIS2 and GDPR compliance assessment') }}</li>
				</ul>

				<div class="its__links">
					<a :href="CONTACT_URL" target="_blank" rel="noopener noreferrer">
						{{ t('nccare', 'Contact us') }}
					</a>
					<a :href="LANDING_URL" target="_blank" rel="noopener noreferrer">
						{{ t('nccare', 'Learn more') }}
					</a>
					<a :href="SCAN_URL" target="_blank" rel="noopener noreferrer">
						{{ t('nccare', 'Nextcloud security scan') }}
					</a>
					<a :href="HEADERS_URL" target="_blank" rel="noopener noreferrer">
						{{ t('nccare', 'HTTP header check') }}
					</a>
				</div>

				<p class="its__version">
					{{ t('nccare', 'NC Care version {version}', { version: status.app_version }) }}
				</p>
			</NcSettingsSection>
		</template>

		<!-- ── RESET DIALOG ──────────────────────────────────────────────── -->
		<NcDialog v-if="showResetDialog"
			:name="t('nccare', 'Reset registration')"
			:open="showResetDialog"
			@update:open="showResetDialog = $event">
			<template #default>
				<NcNoteCard type="error">
					<p>
						<strong>{{ t('nccare', 'This action cannot be undone.') }}</strong>
					</p>
					<p class="its__spaced">
						{{ t('nccare', 'The current installation identifier and token will be deleted, a new identifier generated, and the instance registered again.') }}
					</p>
					<p class="its__spaced">
						{{ t('nccare', 'The previous installation stays in the support panel as a separate entry, so its history is not lost.') }}
					</p>
				</NcNoteCard>
			</template>
			<template #actions>
				<NcButton type="tertiary" @click="showResetDialog = false">
					{{ t('nccare', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="confirmReset">
					{{ t('nccare', 'Reset registration') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<style scoped>
.its {
	max-width: 800px;
}

.its__banner {
	margin-bottom: 16px;
}

.its__paragraph {
	margin-bottom: 12px;
	line-height: 1.5;
	max-width: 70ch;
}

/*
 * Status chips use the Nextcloud colour pairs: --color-X is a background tint
 * and --color-X-text the matching foreground, so they stay readable in both
 * the light and the dark theme.
 */
.chips {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin-bottom: 18px;
}

.chip {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	border-radius: var(--border-radius-pill, 100px);
	padding: 5px 16px;
	font-size: 0.9em;
	font-weight: 600;
	border: 1px solid transparent;
}

.chip__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background-color: currentcolor;
	flex-shrink: 0;
}

.chip--ok {
	background-color: var(--color-success);
	color: var(--color-success-text);
	border-color: var(--color-success-hover);
}

.chip--warn {
	background-color: var(--color-warning);
	color: var(--color-warning-text);
	border-color: var(--color-warning-hover);
}

.chip--error {
	background-color: var(--color-error);
	color: var(--color-error-text);
	border-color: var(--color-error-hover);
}

.chip--neutral {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	border-color: var(--color-border-dark);
}

.its__info {
	display: grid;
	grid-template-columns: minmax(140px, max-content) 1fr;
	gap: 8px 18px;
	margin: 16px 0 20px;
}

.its__info dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.its__info dd {
	margin: 0;
	font-family: monospace;
	overflow-wrap: anywhere;
}

.its__error {
	color: var(--color-error-text);
	font-weight: 600;
}

.its__actions {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin-top: 16px;
}

.its__spaced {
	margin-top: 8px;
}

.its__services {
	margin: 0 0 16px;
	padding-inline-start: 20px;
}

.its__services li {
	margin-bottom: 6px;
}

.its__links {
	display: flex;
	flex-wrap: wrap;
	gap: 18px;
	margin-bottom: 16px;
}

.its__links a {
	font-weight: 600;
	text-decoration: underline;
}

.its__version {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 480px) {
	.its__info {
		grid-template-columns: 1fr;
		gap: 2px 0;
	}

	.its__info dd {
		margin-bottom: 10px;
	}
}
</style>
