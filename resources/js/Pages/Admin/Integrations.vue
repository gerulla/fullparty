<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
// @ts-ignore
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import { router, useForm, usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

type HealthcheckBucket = {
	status: 'healthy' | 'degraded' | 'unhealthy' | 'unknown'
	checked: number
	failed: number
	degraded: number
	started_at: string
	ended_at: string
}

type HealthcheckStats = {
	total: number
	failed: number
	degraded: number
	uptime: number | null
	buckets: HealthcheckBucket[]
}

type LatestHealthcheck = {
	status: 'healthy' | 'degraded' | 'unhealthy'
	checked_at: string | null
	response_status: number | null
	duration_ms: number | null
	error: string | null
}

type IntegrationClient = {
	id: number
	name: string
	type: string
	status: string
	outbound_events_url: string | null
	healthcheck_url: string | null
	has_api_token: boolean
	has_webhook_signing_secret: boolean
	scopes: string[]
	allowed_events: string[]
	last_event_sent_at: string | null
	last_event_failed_at: string | null
	last_event_error: string | null
	last_healthcheck_at: string | null
	last_healthcheck_ok_at: string | null
	last_healthcheck_failed_at: string | null
	last_healthcheck_error: string | null
	latest_healthcheck: LatestHealthcheck | null
	healthcheck_stats: {
		day: HealthcheckStats
		week: HealthcheckStats
	}
	last_api_used_at: string | null
	created_at: string | null
}

type IntegrationCredentials = {
	client_id: number
	client_name: string
	api_token: string | null
	webhook_signing_secret: string | null
}

const props = defineProps<{
	clients: IntegrationClient[]
	options: {
		types: string[]
		statuses: string[]
		scopes: string[]
		events: string[]
	}
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const confirmationModal = useConfirmationModal();
const editingClientId = ref<number | null>(null);
const credentials = ref<IntegrationCredentials | null>(null);
const healthcheckClientId = ref<number | null>(null);
const defaultAllowedEvents = () => [...props.options.events];

const form = useForm({
	name: '',
	type: 'discord_bot',
	status: 'active',
	outbound_events_url: '',
	healthcheck_url: '',
	scopes: ['runs:read', 'users:read', 'users:write', 'guilds:write'],
	allowed_events: defaultAllowedEvents(),
});

const typeOptions = computed(() => props.options.types.map((type) => ({
	label: t(`admin.integrations.types.${type}`),
	value: type,
})));

const statusOptions = computed(() => props.options.statuses.map((status) => ({
	label: t(`admin.integrations.statuses.${status}`),
	value: status,
})));

const scopeOptions = computed(() => props.options.scopes.map((scope) => ({
	label: labelForScope(scope),
	value: scope,
})));

const eventOptions = computed(() => props.options.events.map((event) => ({
	label: labelForEvent(event),
	value: event,
})));

const labelForScope = (scope: string) => t(`admin.integrations.scopes.${scope.replace(':', '_')}`);
const labelForEvent = (event: string) => t(`admin.integrations.events.${event.replaceAll('.', '_')}`);
const healthcheckBucketClass = (status: HealthcheckBucket['status']) => {
	if (status === 'healthy') {
		return 'bg-success-500';
	}

	if (status === 'degraded') {
		return 'bg-warning-500';
	}

	if (status === 'unhealthy') {
		return 'bg-error-500';
	}

	return 'bg-muted';
};
const formatHealthcheckTime = (value: string) => new Date(value).toLocaleString(locale.value);
const healthcheckBucketTitle = (bucket: HealthcheckBucket) => t('admin.integrations.health_status.bucket_title', {
	status: t(`admin.integrations.health_status.statuses.${bucket.status}`),
	checked: bucket.checked,
	degraded: bucket.degraded,
	failed: bucket.failed,
	start: formatHealthcheckTime(bucket.started_at),
	end: formatHealthcheckTime(bucket.ended_at),
});
const uptimeLabel = (stats: HealthcheckStats) => stats.uptime === null
	? t('admin.integrations.no_samples')
	: `${stats.uptime}%`;
const latestHealthcheckColor = (healthcheck: LatestHealthcheck | null) => {
	if (!healthcheck) {
		return 'neutral';
	}

	if (healthcheck.status === 'healthy') {
		return 'success';
	}

	if (healthcheck.status === 'degraded') {
		return 'warning';
	}

	return 'error';
};
const latestHealthcheckStatusLabel = (healthcheck: LatestHealthcheck | null) => {
	if (!healthcheck) {
		return t('admin.integrations.health_status.statuses.unknown');
	}

	return t(`admin.integrations.health_status.statuses.${healthcheck.status}`);
};
const latestHealthcheckMeta = (healthcheck: LatestHealthcheck | null) => {
	if (!healthcheck) {
		return t('admin.integrations.health_status.no_latest_result');
	}

	const pieces = [
		healthcheck.checked_at ? formatHealthcheckTime(healthcheck.checked_at) : null,
		healthcheck.response_status !== null ? t('admin.integrations.health_status.http_status', { status: healthcheck.response_status }) : null,
		healthcheck.duration_ms !== null ? t('admin.integrations.health_status.duration', { duration: healthcheck.duration_ms }) : null,
	].filter(Boolean);

	return pieces.join(' · ');
};

const resetForm = () => {
	editingClientId.value = null;
	form.defaults({
		name: '',
		type: 'discord_bot',
		status: 'active',
		outbound_events_url: '',
		healthcheck_url: '',
		scopes: ['runs:read', 'users:read', 'users:write', 'guilds:write'],
		allowed_events: defaultAllowedEvents(),
	});
	form.reset();
	form.clearErrors();
};

const editClient = (client: IntegrationClient) => {
	editingClientId.value = client.id;
	form.defaults({
		name: client.name,
		type: client.type,
		status: client.status,
		outbound_events_url: client.outbound_events_url ?? '',
		healthcheck_url: client.healthcheck_url ?? '',
		scopes: [...client.scopes],
		allowed_events: client.allowed_events.filter((event) => props.options.events.includes(event)),
	});
	form.reset();
	form.clearErrors();
};

const submit = () => {
	if (editingClientId.value === null) {
		form.post(route('admin.integrations.store'), {
			onSuccess: () => resetForm(),
		});

		return;
	}

	form.put(route('admin.integrations.update', editingClientId.value), {
		onSuccess: () => resetForm(),
	});
};

const regenerateApiToken = async (client: IntegrationClient) => {
	await confirmationModal.open({
		title: t('admin.integrations.regenerate_api_token_modal.title'),
		description: t('admin.integrations.regenerate_api_token_modal.description'),
		severity: 'warning',
		warningText: t('admin.integrations.regenerate_api_token_modal.warning'),
		confirmLabel: t('admin.integrations.regenerate_api_token_modal.confirm'),
		confirmIcon: 'i-lucide-key-round',
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });

			return await new Promise<boolean>((resolve) => {
				router.post(route('admin.integrations.api-token.regenerate', client.id), {}, {
					onSuccess: () => resolve(true),
					onError: () => resolve(false),
					onFinish: () => patch({ confirmLoading: false }),
				});
			});
		},
	});
};

const regenerateWebhookSecret = async (client: IntegrationClient) => {
	await confirmationModal.open({
		title: t('admin.integrations.regenerate_webhook_secret_modal.title'),
		description: t('admin.integrations.regenerate_webhook_secret_modal.description'),
		severity: 'warning',
		warningText: t('admin.integrations.regenerate_webhook_secret_modal.warning'),
		confirmLabel: t('admin.integrations.regenerate_webhook_secret_modal.confirm'),
		confirmIcon: 'i-lucide-lock-keyhole',
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });

			return await new Promise<boolean>((resolve) => {
				router.post(route('admin.integrations.webhook-secret.regenerate', client.id), {}, {
					onSuccess: () => resolve(true),
					onError: () => resolve(false),
					onFinish: () => patch({ confirmLoading: false }),
				});
			});
		},
	});
};

const runHealthcheck = (client: IntegrationClient) => {
	healthcheckClientId.value = client.id;

	router.post(route('admin.integrations.healthcheck.run', client.id), {}, {
		onFinish: () => {
			healthcheckClientId.value = null;
		},
	});
};

watch(
	() => page.props.flash?.data?.integration_credentials,
	(value) => {
		credentials.value = (value as IntegrationCredentials | null) ?? null;
	},
	{ immediate: true },
);

watch(
	() => page.props.flash?.success,
	(success) => {
		if (!success) {
			return;
		}

		const successKeys = Array.isArray(success) ? success : [success];

		if (successKeys.includes('integration_client_healthcheck_ran')) {
			toast.add({
				title: t('admin.integrations.healthcheck_toast.title'),
				description: t('admin.integrations.healthcheck_toast.description'),
				color: 'info',
				icon: 'i-lucide-heart-pulse',
			});

			return;
		}

		if (successKeys.some((key) => String(key).startsWith('integration_client_'))) {
			toast.add({
				title: t('general.success'),
				description: t('admin.integrations.saved'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		}
	},
	{ immediate: true },
);
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('admin.integrations.title')"
			:subtitle="t('admin.integrations.subtitle')"
		>
			<UBadge
				color="warning"
				variant="subtle"
				icon="i-lucide-shield"
				:label="t('admin.integrations.admin_only')"
			/>
		</PageHeader>

		<UAlert
			v-if="credentials"
			class="mt-6"
			color="warning"
			variant="subtle"
			icon="i-lucide-key-round"
			:title="t('admin.integrations.credentials.title', { name: credentials.client_name })"
			:description="t('admin.integrations.credentials.description')"
		>
			<template #description>
				<div class="mt-3 space-y-3">
					<p class="text-sm text-toned">
						{{ t('admin.integrations.credentials.description') }}
					</p>
					<div v-if="credentials.api_token" class="space-y-1">
						<p class="text-xs font-semibold uppercase tracking-wide text-muted">
							{{ t('admin.integrations.credentials.api_token') }}
						</p>
						<code class="block break-all rounded-sm bg-default/70 p-3 text-xs text-highlighted">
							{{ credentials.api_token }}
						</code>
					</div>
					<div v-if="credentials.webhook_signing_secret" class="space-y-1">
						<p class="text-xs font-semibold uppercase tracking-wide text-muted">
							{{ t('admin.integrations.credentials.webhook_secret') }}
						</p>
						<code class="block break-all rounded-sm bg-default/70 p-3 text-xs text-highlighted">
							{{ credentials.webhook_signing_secret }}
						</code>
					</div>
					<UButton
						color="neutral"
						variant="soft"
						icon="i-lucide-check"
						:label="t('general.close')"
						@click="credentials = null"
					/>
				</div>
			</template>
		</UAlert>

		<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[26rem_minmax(0,1fr)]">
			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="space-y-1">
						<h2 class="text-base font-semibold text-highlighted">
							{{ editingClientId === null ? t('admin.integrations.form.create_title') : t('admin.integrations.form.edit_title') }}
						</h2>
						<p class="text-sm text-muted">
							{{ t('admin.integrations.form.description') }}
						</p>
					</div>
				</template>

				<form class="space-y-4" @submit.prevent="submit">
					<UFormField :label="t('admin.integrations.fields.name')" :error="form.errors.name" required>
						<UInput v-model="form.name" class="w-full" :placeholder="t('admin.integrations.placeholders.name')" />
					</UFormField>

					<UFormField :label="t('admin.integrations.fields.type')" :error="form.errors.type" required>
						<USelect v-model="form.type" :items="typeOptions" class="w-full" />
					</UFormField>

					<UFormField :label="t('admin.integrations.fields.status')" :error="form.errors.status" required>
						<USelect v-model="form.status" :items="statusOptions" class="w-full" />
					</UFormField>

					<UFormField :label="t('admin.integrations.fields.outbound_events_url')" :error="form.errors.outbound_events_url">
						<UInput v-model="form.outbound_events_url" class="w-full" :placeholder="t('admin.integrations.placeholders.outbound_events_url')" />
					</UFormField>

					<UFormField :label="t('admin.integrations.fields.healthcheck_url')" :error="form.errors.healthcheck_url">
						<UInput v-model="form.healthcheck_url" class="w-full" :placeholder="t('admin.integrations.placeholders.healthcheck_url')" />
					</UFormField>

					<UFormField :label="t('admin.integrations.fields.scopes')" :error="form.errors.scopes">
						<UCheckboxGroup v-model="form.scopes" :items="scopeOptions" />
					</UFormField>

					<UFormField :label="t('admin.integrations.fields.allowed_events')" :error="form.errors.allowed_events">
						<UCheckboxGroup v-model="form.allowed_events" :items="eventOptions" />
					</UFormField>

					<div class="flex flex-wrap justify-end gap-2">
						<UButton
							v-if="editingClientId !== null"
							type="button"
							color="neutral"
							variant="soft"
							icon="i-lucide-x"
							:label="t('general.cancel')"
							@click="resetForm"
						/>
						<UButton
							type="submit"
							color="primary"
							icon="i-lucide-save"
							:loading="form.processing"
							:label="editingClientId === null ? t('admin.integrations.form.create') : t('general.save')"
						/>
					</div>
				</form>
			</UCard>

			<div class="space-y-4">
				<UCard
					v-for="client in clients"
					:key="client.id"
					class="dark:bg-elevated/25"
				>
					<div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
						<div class="min-w-0 space-y-3">
							<div class="flex flex-wrap items-center gap-2">
								<h2 class="text-lg font-semibold text-highlighted">
									{{ client.name }}
								</h2>
					<UBadge :label="t(`admin.integrations.types.${client.type}`)" color="primary" variant="subtle" />
								<UBadge
									:label="t(`admin.integrations.statuses.${client.status}`)"
									:color="client.status === 'active' ? 'success' : client.status === 'paused' ? 'warning' : 'error'"
									variant="subtle"
								/>
							</div>

							<p class="break-all text-sm text-muted">
								{{ client.outbound_events_url || t('admin.integrations.no_endpoint') }}
							</p>
							<p class="break-all text-xs text-muted">
								{{ t('admin.integrations.healthcheck_endpoint', { value: client.healthcheck_url || t('admin.integrations.no_healthcheck') }) }}
							</p>

							<div class="flex flex-wrap gap-2">
								<UBadge
									v-for="scope in client.scopes"
									:key="scope"
									color="neutral"
									variant="subtle"
									:label="labelForScope(scope)"
								/>
								<UBadge
									v-for="event in client.allowed_events"
									:key="event"
									color="info"
									variant="subtle"
									:label="labelForEvent(event)"
								/>
							</div>

							<div class="grid grid-cols-1 gap-2 text-xs text-muted md:grid-cols-2">
								<span>{{ t('admin.integrations.meta.api_token', { value: client.has_api_token ? t('general.yes') : t('general.no') }) }}</span>
								<span>{{ t('admin.integrations.meta.webhook_secret', { value: client.has_webhook_signing_secret ? t('general.yes') : t('general.no') }) }}</span>
								<span>{{ t('admin.integrations.meta.last_api_used', { value: client.last_api_used_at ?? t('admin.integrations.never') }) }}</span>
								<span>{{ t('admin.integrations.meta.last_event_sent', { value: client.last_event_sent_at ?? t('admin.integrations.never') }) }}</span>
								<span>{{ t('admin.integrations.meta.last_healthcheck', { value: client.last_healthcheck_at ?? t('admin.integrations.never') }) }}</span>
							</div>

							<div class="space-y-3 rounded-sm border border-default/60 bg-default/30 p-3">
								<div class="flex items-center justify-between gap-3">
									<p class="text-xs font-semibold uppercase tracking-wide text-muted">
										{{ t('admin.integrations.health_status.title') }}
									</p>
									<div class="flex items-center gap-3 text-[11px] text-muted">
										<span class="inline-flex items-center gap-1">
											<span class="h-2 w-2 bg-success-500" />
											{{ t('admin.integrations.health_status.statuses.healthy') }}
										</span>
										<span class="inline-flex items-center gap-1">
											<span class="h-2 w-2 bg-warning-500" />
											{{ t('admin.integrations.health_status.statuses.degraded') }}
										</span>
										<span class="inline-flex items-center gap-1">
											<span class="h-2 w-2 bg-error-500" />
											{{ t('admin.integrations.health_status.statuses.unhealthy') }}
										</span>
										<span class="inline-flex items-center gap-1">
											<span class="h-2 w-2 bg-muted" />
											{{ t('admin.integrations.health_status.statuses.unknown') }}
										</span>
									</div>
								</div>

								<div class="flex flex-col gap-2 border border-default/70 bg-background/40 px-3 py-2 text-xs sm:flex-row sm:items-start sm:justify-between">
									<div class="min-w-0 space-y-1">
										<p class="font-semibold text-toned">
											{{ t('admin.integrations.health_status.latest_result') }}
										</p>
										<p class="text-muted">
											{{ latestHealthcheckMeta(client.latest_healthcheck) }}
										</p>
										<p
											v-if="client.latest_healthcheck?.error"
											class="break-words text-error"
										>
											{{ client.latest_healthcheck.error }}
										</p>
									</div>
									<UBadge
										:color="latestHealthcheckColor(client.latest_healthcheck)"
										variant="subtle"
										:label="latestHealthcheckStatusLabel(client.latest_healthcheck)"
									/>
								</div>

								<div class="space-y-2">
									<div class="flex items-center justify-between gap-3 text-xs">
										<span class="text-toned">{{ t('admin.integrations.health_status.day') }}</span>
										<span class="text-muted">{{ uptimeLabel(client.healthcheck_stats.day) }}</span>
									</div>
									<div class="flex h-7 gap-0.5">
										<span
											v-for="(bucket, index) in client.healthcheck_stats.day.buckets"
											:key="`day-${client.id}-${index}`"
											class="min-w-0 flex-1 rounded-[2px]"
											:class="healthcheckBucketClass(bucket.status)"
											:title="healthcheckBucketTitle(bucket)"
										/>
									</div>
								</div>

								<div class="space-y-2">
									<div class="flex items-center justify-between gap-3 text-xs">
										<span class="text-toned">{{ t('admin.integrations.health_status.week') }}</span>
										<span class="text-muted">{{ uptimeLabel(client.healthcheck_stats.week) }}</span>
									</div>
									<div class="flex h-7 gap-0.5">
										<span
											v-for="(bucket, index) in client.healthcheck_stats.week.buckets"
											:key="`week-${client.id}-${index}`"
											class="min-w-0 flex-1 rounded-[2px]"
											:class="healthcheckBucketClass(bucket.status)"
											:title="healthcheckBucketTitle(bucket)"
										/>
									</div>
								</div>
							</div>

							<UAlert
								v-if="client.last_event_failed_at"
								color="error"
								variant="subtle"
								icon="i-lucide-triangle-alert"
								:title="t('admin.integrations.last_failure')"
								:description="client.last_event_error ?? client.last_event_failed_at"
							/>
							<UAlert
								v-if="client.last_healthcheck_failed_at"
								color="error"
								variant="subtle"
								icon="i-lucide-heart-pulse"
								:title="t('admin.integrations.last_healthcheck_failure')"
								:description="client.last_healthcheck_error ?? client.last_healthcheck_failed_at"
							/>
						</div>

						<div class="flex shrink-0 flex-wrap gap-2">
							<UButton color="neutral" variant="soft" icon="i-lucide-pencil" :label="t('general.edit')" @click="editClient(client)" />
							<UButton
								color="neutral"
								variant="soft"
								icon="i-lucide-heart-pulse"
								:loading="healthcheckClientId === client.id"
								:disabled="!client.healthcheck_url"
								:label="t('admin.integrations.actions.healthcheck')"
								@click="runHealthcheck(client)"
							/>
							<UButton color="warning" variant="soft" icon="i-lucide-key-round" :label="t('admin.integrations.actions.api_token')" @click="regenerateApiToken(client)" />
							<UButton color="warning" variant="soft" icon="i-lucide-lock-keyhole" :label="t('admin.integrations.actions.webhook_secret')" @click="regenerateWebhookSecret(client)" />
						</div>
					</div>
				</UCard>

				<UCard v-if="clients.length === 0" class="dark:bg-elevated/25">
					<div class="py-10 text-center text-sm text-muted">
						{{ t('admin.integrations.empty') }}
					</div>
				</UCard>
			</div>
		</div>
	</div>
</template>
