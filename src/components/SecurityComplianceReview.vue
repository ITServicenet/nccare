<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { checkMessage, describeCheck } from '../checkCatalog'
import type { CheckDescriptor } from '../checkCatalog'
import type { Check, CheckMap, CheckStatus, CheckSummary } from '../types'

const props = defineProps<{
	checks: CheckMap
	summary: CheckSummary
}>()

const APP_ID = 'nccare'
const CONTACT_URL = 'https://itservicenet.net/contattaci/'

/** Failing checks first, so the actionable items are visible without scrolling. */
const ordered = computed(() => {
	const weight: Record<CheckStatus, number> = { error: 0, warn: 1, unknown: 2, ok: 3 }
	return Object.entries(props.checks)
		.map(([key, check]) => ({ key, check, ...describeCheck(key) }))
		.sort((a, b) => {
			const diff = weight[a.check.status] - weight[b.check.status]
			return diff !== 0 ? diff : a.label.localeCompare(b.label)
		})
})

const actionableCount = computed(() => props.summary.error + props.summary.warn)

const statusLabel: Record<CheckStatus, string> = {
	ok: t(APP_ID, 'All good'),
	warn: t(APP_ID, 'To review'),
	error: t(APP_ID, 'Action recommended'),
	unknown: t(APP_ID, 'Not detected'),
}

/** What this check's result means, worded as the customer portal words it. */
function messageFor(item: CheckDescriptor & { check: Check }): string {
	return checkMessage(item, item.check.status, item.check.value)
}

/** Glyph shown inside the marker, so status is not conveyed by colour alone. */
const statusGlyph: Record<CheckStatus, string> = {
	ok: '✓',
	warn: '!',
	error: '✕',
	unknown: '?',
}

function formatValue(value: unknown): string {
	if (value === null || value === undefined || value === '') return ''
	if (typeof value === 'boolean') return value ? t(APP_ID, 'yes') : t(APP_ID, 'no')
	if (Array.isArray(value)) return value.join(', ')
	if (typeof value === 'object') return JSON.stringify(value)
	return String(value)
}
</script>

<template>
	<div class="review">
		<div class="summary">
			<span class="pill pill--ok">
				<span class="pill__dot" />
				{{ summary.ok }} {{ t('nccare', 'all good') }}
			</span>
			<span v-if="summary.warn" class="pill pill--warn">
				<span class="pill__dot" />
				{{ summary.warn }} {{ t('nccare', 'to review') }}
			</span>
			<span v-if="summary.error" class="pill pill--error">
				<span class="pill__dot" />
				{{ summary.error }} {{ t('nccare', 'recommended actions') }}
			</span>
			<span v-if="summary.unknown" class="pill pill--unknown">
				<span class="pill__dot" />
				{{ summary.unknown }} {{ t('nccare', 'not detected') }}
			</span>
		</div>

		<ul class="checks">
			<li v-for="item in ordered"
				:key="item.key"
				class="check"
				:class="`check--${item.check.status}`">
				<span class="check__marker" :title="statusLabel[item.check.status]" aria-hidden="true">
					{{ statusGlyph[item.check.status] }}
				</span>

				<div class="check__body">
					<div class="check__head">
						<span class="check__label">{{ item.label }}</span>
						<span v-if="formatValue(item.check.value)" class="check__value">
							{{ formatValue(item.check.value) }}
						</span>
					</div>

					<p class="check__hint">
						{{ messageFor(item) }}
					</p>

					<p v-if="['warn', 'error'].includes(item.check.status) && item.remedy" class="check__remedy">
						<strong>{{ t('nccare', 'How to fix it') }}:</strong>
						{{ item.remedy }}
					</p>
				</div>

				<span class="check__status">{{ statusLabel[item.check.status] }}</span>
			</li>
		</ul>

		<NcNoteCard v-if="actionableCount > 0" type="info">
			<p>
				<strong>
					{{ t('nccare', 'We found {count} item(s) worth improving.', { count: actionableCount }) }}
				</strong>
			</p>
			<p class="cta__text">
				{{ t('nccare', 'The ITServicenet team can review these findings with you and bring the instance in line with enterprise security standards.') }}
			</p>
			<a :href="CONTACT_URL"
				target="_blank"
				rel="noopener noreferrer"
				class="cta__link">
				{{ t('nccare', 'Contact ITServicenet') }}
			</a>
		</NcNoteCard>

		<NcNoteCard v-else type="success">
			{{ t('nccare', 'Every automatic check passed. Well done.') }}
		</NcNoteCard>
	</div>
</template>

<style scoped>
/*
 * Nextcloud exposes each semantic colour as a pair: --color-X is a background
 * tint and --color-X-text the foreground that stays readable on it. Using the
 * pair keeps the traffic lights legible in both the light and the dark theme,
 * which a single saturated colour would not.
 */

.summary {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 18px;
}

.pill {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	border-radius: var(--border-radius-pill, 100px);
	padding: 4px 14px;
	font-size: 0.9em;
	font-weight: 600;
	border: 1px solid transparent;
}

.pill__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background-color: currentcolor;
	flex-shrink: 0;
}

.pill--ok {
	background-color: var(--color-success);
	color: var(--color-success-text);
	border-color: var(--color-success-hover);
}

.pill--warn {
	background-color: var(--color-warning);
	color: var(--color-warning-text);
	border-color: var(--color-warning-hover);
}

.pill--error {
	background-color: var(--color-error);
	color: var(--color-error-text);
	border-color: var(--color-error-hover);
}

.pill--unknown {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	border-color: var(--color-border-dark);
}

.checks {
	list-style: none;
	margin: 0 0 18px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	overflow: hidden;
}

.check {
	display: flex;
	align-items: flex-start;
	gap: 14px;
	padding: 14px 16px;
	border-bottom: 1px solid var(--color-border);
}

.check:last-child {
	border-bottom: none;
}

.check:hover {
	background-color: var(--color-background-hover);
}

/* Round marker carrying both the colour and a glyph. */
.check__marker {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	flex-shrink: 0;
	font-size: 0.8em;
	font-weight: 700;
	line-height: 1;
	border: 1px solid transparent;
}

.check--ok .check__marker {
	background-color: var(--color-success);
	color: var(--color-success-text);
	border-color: var(--color-success-hover);
}

.check--warn .check__marker {
	background-color: var(--color-warning);
	color: var(--color-warning-text);
	border-color: var(--color-warning-hover);
}

.check--error .check__marker {
	background-color: var(--color-error);
	color: var(--color-error-text);
	border-color: var(--color-error-hover);
}

.check--unknown .check__marker {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	border-color: var(--color-border-dark);
}

.check__body {
	flex: 1;
	min-width: 0;
}

.check__head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 10px;
}

.check__label {
	font-weight: 600;
}

.check__value {
	font-family: monospace;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.check__hint {
	margin: 3px 0 0;
	font-size: 0.9em;
	line-height: 1.45;
	color: var(--color-text-maxcontrast);
}

.check__remedy {
	margin: 8px 0 0;
	font-size: 0.9em;
	line-height: 1.45;
	padding-inline-start: 12px;
	border-inline-start: 3px solid var(--color-border-dark);
}

.check__status {
	font-size: 0.75em;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
	white-space: nowrap;
	padding-top: 4px;
}

.cta__text {
	margin: 8px 0;
}

.cta__link {
	font-weight: 600;
	text-decoration: underline;
}

@media (max-width: 560px) {
	.check {
		flex-wrap: wrap;
	}

	.check__status {
		width: 100%;
		padding-inline-start: 36px;
		padding-top: 0;
	}
}
</style>
