<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

type AuditLogRow = {
	id: number
	action: string
	severity: string
	scope?: {
		type: string | null
		id: number | null
		label: string | null
	}
	actor: {
		id: number | null
		name: string
		avatar_url: string | null
		is_system: boolean
	}
	subject: {
		type: string | null
		id: number | null
		name: string
		avatar_url: string | null
		is_system: boolean
	}
	changes: Array<{
		label: string
		old: string
		new: string
	}>
	details: Array<string>
	created_at: string
}

const props = withDefaults(defineProps<{
	row: AuditLogRow
	showScope?: boolean
}>(), {
	showScope: false,
});

const { t } = useI18n();
const changesExpanded = ref(false);
const detailsExpanded = ref(false);

const severityBadge = computed(() => ({
	info: { color: 'neutral', label: t('audit_log.severities.info'), icon: 'i-lucide-info' },
	moderation_change: { color: 'primary', label: t('audit_log.severities.moderation_change'), icon: 'i-lucide-shield' },
	severe_change: { color: 'warning', label: t('audit_log.severities.severe_change'), icon: 'i-lucide-triangle-alert' },
	critical: { color: 'error', label: t('audit_log.severities.critical'), icon: 'i-lucide-octagon-alert' },
}[props.row.severity] ?? { color: 'neutral', label: props.row.severity, icon: 'i-lucide-info' }));

const actionStyle = computed(() => {
	const action = props.row.action;

	if (action.includes('.created') || action.includes('.joined') || action.includes('.registered') || action.includes('.linked') || action.includes('.unbanned')) {
		return {
			color: 'success',
			icon: 'i-lucide-badge-plus',
			textClass: 'text-success',
			bgClass: 'bg-success/8 ring-success/20',
		};
	}

	if (action.includes('.updated') || action.includes('.promoted') || action.includes('.demoted') || action.includes('.transferred')) {
		return {
			color: 'warning',
			icon: 'i-lucide-pencil-line',
			textClass: 'text-warning',
			bgClass: 'bg-warning/8 ring-warning/20',
		};
	}

	if (action.includes('.deleted') || action.includes('.removed') || action.includes('.revoked') || action.includes('.banned') || action.includes('.left')) {
		return {
			color: 'error',
			icon: 'i-lucide-trash-2',
			textClass: 'text-error',
			bgClass: 'bg-error/8 ring-error/20',
		};
	}

	return {
		color: 'neutral',
		icon: 'i-lucide-scroll-text',
		textClass: 'text-muted',
		bgClass: 'bg-muted/20 ring-default',
	};
});

const showAffectedUser = computed(() => (
	props.row.subject.type === 'App\\Models\\User'
	&& !props.row.subject.is_system
	&& props.row.subject.id !== props.row.actor.id
));

const showNamedSubject = computed(() => (
	!showAffectedUser.value
	&& props.row.subject.type === 'App\\Models\\ActivityType'
	&& !props.row.subject.is_system
	&& Boolean(props.row.subject.name)
));

const baseActionText = computed(() => {
	const key = `audit_log.activity.${props.row.action}`;
	const translated = t(key);

	return translated === key
		? props.row.action.replaceAll('.', ' ')
		: translated;
});

const actionText = computed(() => {
	if (showAffectedUser.value) {
		const key = `audit_log.activity_targeted.${props.row.action}`;
		const translated = t(key);

		return translated === key
			? baseActionText.value
			: translated;
	}

	if (showNamedSubject.value) {
		const key = `audit_log.activity_named.${props.row.action}`;
		const translated = t(key);

		return translated === key
			? baseActionText.value
			: translated;
	}

	return baseActionText.value;
});

const scopeBadge = computed(() => ({
	group: { color: 'primary', label: t('audit_log.scopes.group') },
	user: { color: 'neutral', label: t('audit_log.scopes.user') },
	character: { color: 'secondary', label: t('audit_log.scopes.character') },
	admin: { color: 'warning', label: t('audit_log.scopes.admin') },
	system: { color: 'error', label: t('audit_log.scopes.system') },
}[props.row.scope?.type ?? ''] ?? { color: 'neutral', label: props.row.scope?.type ?? t('audit_log.scopes.unknown') }));

const scopeSuffix = computed(() => {
	if (!props.showScope || !props.row.scope?.label) {
		return '';
	}

	return ` in ${props.row.scope.label}`;
});

const detailLines = computed(() => {
	const lines = [...props.row.details];

	if (props.showScope && props.row.scope?.label) {
		lines.unshift(`Scope Name: ${props.row.scope.label}`);
	}

	if (props.showScope && props.row.scope?.id !== null && props.row.scope?.id !== undefined) {
		lines.splice(props.row.scope?.label ? 1 : 0, 0, `Scope ID: ${props.row.scope.id}`);
	}

	return lines;
});

const formatTimestamp = (value: string) => new Intl.DateTimeFormat(undefined, {
	year: 'numeric',
	month: 'short',
	day: 'numeric',
	hour: '2-digit',
	minute: '2-digit',
}).format(new Date(value));
</script>

<template>
	<UCard class="dark:bg-elevated/25">
		<div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
			<div class="min-w-0 flex-1">
				<div class="flex items-start gap-4">
					<UUser
						:name="row.actor.name"
						size="lg"
						:avatar="{
							src: row.actor.avatar_url ?? undefined,
							icon: row.actor.is_system ? 'i-lucide-cpu' : 'i-lucide-user-round',
							alt: row.actor.name,
						}"
					/>
					<div class="min-w-0 flex-1 pt-1">
						<div class="flex flex-wrap items-center gap-2">
							<div
								class="inline-flex h-8 w-8 items-center justify-center rounded-full ring-1"
								:class="actionStyle.bgClass"
							>
								<UIcon :name="actionStyle.icon" class="text-base" :class="actionStyle.textClass" />
							</div>
							<p class="text-sm text-toned">
								<span class="font-semibold">{{ row.actor.name }}</span>
								<span class="ml-1" :class="actionStyle.textClass">{{ actionText }}</span>
								<span v-if="showAffectedUser" class="ml-1 font-semibold text-toned">{{ row.subject.name }}</span>
								<span v-if="showNamedSubject" class="ml-1 font-semibold text-toned">{{ row.subject.name }}</span>
								<span v-if="scopeSuffix" class="ml-1 text-muted">{{ scopeSuffix }}</span>
							</p>
							<UBadge
								:label="severityBadge.label"
								:color="severityBadge.color"
								:icon="severityBadge.icon"
								variant="subtle"
								size="sm"
							/>
							<UBadge
								v-if="showScope && row.scope?.type"
								:label="scopeBadge.label"
								:color="scopeBadge.color"
								variant="soft"
								size="sm"
							/>
						</div>

						<div
							v-if="row.changes.length"
							class="mt-4 rounded-xl border border-default/70 bg-muted/15 p-3"
						>
							<div class="flex items-center justify-between gap-3">
								<p class="text-xs font-semibold uppercase tracking-wide text-muted">
									{{ t('audit_log.list.changes') }}
								</p>

								<UButton
									color="neutral"
									variant="ghost"
									size="xs"
									:icon="changesExpanded ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
									:label="changesExpanded
										? t('audit_log.list.hide_changes')
										: t('audit_log.list.show_changes')"
									@click="changesExpanded = !changesExpanded"
								/>
							</div>

							<div v-if="changesExpanded" class="mt-3 space-y-3">
								<div
									v-for="change in row.changes"
									:key="`${row.id}-${change.label}`"
									class="rounded-lg border border-default/70 bg-default/30 p-3"
								>
									<p class="text-sm font-medium text-toned">{{ change.label }}</p>
									<div class="mt-2 space-y-2">
										<div class="rounded-md bg-error/10 px-3 py-2 font-mono text-sm text-error">
											<span class="mr-2 opacity-80">-</span>{{ change.old }}
										</div>
										<div class="rounded-md bg-success/10 px-3 py-2 font-mono text-sm text-success">
											<span class="mr-2 opacity-80">+</span>{{ change.new }}
										</div>
									</div>
								</div>
							</div>
						</div>

						<div
							v-if="detailLines.length"
							class="mt-4 rounded-xl border border-default/70 bg-muted/15 p-3"
						>
							<div class="flex items-center justify-between gap-3">
							<p class="text-xs font-semibold uppercase tracking-wide text-muted">
								{{ t('audit_log.list.details') }}
							</p>

								<UButton
									color="neutral"
									variant="ghost"
									size="xs"
									:icon="detailsExpanded ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
									:label="detailsExpanded
										? t('audit_log.list.hide_changes')
										: t('audit_log.list.show_changes')"
									@click="detailsExpanded = !detailsExpanded"
								/>
							</div>

							<pre v-if="detailsExpanded" class="mt-3 overflow-x-auto rounded-lg bg-default/30 px-3 py-3 font-mono text-xs leading-6 text-toned"><code>{{ detailLines.join('\n') }}</code></pre>
						</div>
					</div>
				</div>
			</div>

			<div class="shrink-0 lg:pt-1 lg:text-right">
				<p class="text-sm font-medium text-toned">{{ formatTimestamp(row.created_at) }}</p>
				<p class="mt-1 text-xs text-muted">{{ t('audit_log.list.recorded_event') }}</p>
			</div>
		</div>
	</UCard>
</template>

<style scoped>

</style>
