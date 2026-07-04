<script setup lang="ts">
// @ts-ignore
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import type { SettingsLinkedSession } from "@/Types/Settings";
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	sessions: SettingsLinkedSession[]
}>();

const { t, locale } = useI18n();
const confirmationModal = useConfirmationModal();
const revokingSessionId = ref<string | null>(null);

const formatDate = (value: string | null, fallback: string) => {
	if (!value) {
		return fallback;
	}

	return new Intl.DateTimeFormat(locale.value, {
		dateStyle: 'medium',
		timeStyle: 'short',
	}).format(new Date(value));
};

const displayName = (session: SettingsLinkedSession) => {
	return session.name || session.client_name || t('settings.active_sessions.unknown_app');
};

const scopeLabel = (scope: string) => {
	if (scope === 'xivplugin:read') {
		return t('settings.active_sessions.scopes.xivplugin_read');
	}

	return scope;
};

const revokeSession = async (session: SettingsLinkedSession) => {
	await confirmationModal.open({
		title: t('settings.active_sessions.revoke_modal.title'),
		description: t('settings.active_sessions.revoke_modal.description', {
			name: displayName(session),
		}),
		severity: 'error',
		warningText: t('settings.active_sessions.revoke_modal.warning'),
		confirmLabel: t('settings.active_sessions.revoke_modal.confirm'),
		confirmIcon: 'i-lucide-unplug',
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });
			revokingSessionId.value = session.id;

			return await new Promise<boolean>((resolve) => {
				router.delete(route('settings.linked-sessions.destroy', session.id), {
					preserveScroll: true,
					onSuccess: () => {
						resolve(true);
					},
					onError: () => {
						resolve(false);
					},
					onFinish: () => {
						revokingSessionId.value = null;
						patch({ confirmLoading: false });
					},
				});
			});
		},
	});
};
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
				<div class="flex flex-row items-center font-semibold text-md">
					<UIcon name="i-lucide-plug-zap" class="mr-2" size="22" />
					<p>{{ t('settings.active_sessions.title') }}</p>
				</div>
				<p class="text-sm text-muted">
					{{ t('settings.active_sessions.subtitle') }}
				</p>
			</div>
		</template>

		<div v-if="props.sessions.length" class="divide-y divide-neutral-200 dark:divide-neutral-800">
			<div
				v-for="session in props.sessions"
				:key="session.id"
				class="flex flex-col gap-4 py-4 first:pt-0 last:pb-0 md:flex-row md:items-center md:justify-between"
			>
				<div class="flex min-w-0 items-start gap-3">
					<div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-sm bg-primary/10 text-primary-400">
						<UIcon name="i-lucide-gamepad-2" size="24" />
					</div>
					<div class="min-w-0">
						<p class="font-semibold">{{ displayName(session) }}</p>
						<p class="text-sm text-muted">
							{{ t('settings.active_sessions.connected_at', { date: formatDate(session.created_at, t('settings.active_sessions.unknown_date')) }) }}
						</p>
						<p class="text-sm text-muted">
							{{ t('settings.active_sessions.valid_until', { date: formatDate(session.refresh_expires_at || session.expires_at, t('settings.active_sessions.no_expiry')) }) }}
						</p>
						<div v-if="session.scopes.length" class="mt-2 flex flex-wrap gap-2">
							<UBadge
								v-for="scope in session.scopes"
								:key="scope"
								color="neutral"
								variant="soft"
								size="sm"
								class="rounded-none"
							>
								{{ scopeLabel(scope) }}
							</UBadge>
						</div>
					</div>
				</div>

				<UButton
					color="error"
					variant="ghost"
					icon="i-lucide-unplug"
					:loading="revokingSessionId === session.id"
					class="self-start md:self-center"
					@click="revokeSession(session)"
				>
					{{ t('settings.active_sessions.revoke') }}
				</UButton>
			</div>
		</div>

		<div v-else class="flex flex-col items-start gap-2 text-sm text-muted">
			<div class="flex h-11 w-11 items-center justify-center rounded-sm bg-neutral-500/10 text-muted">
				<UIcon name="i-lucide-plug" size="24" />
			</div>
			<p class="font-medium text-toned">{{ t('settings.active_sessions.empty_title') }}</p>
			<p>{{ t('settings.active_sessions.empty_description') }}</p>
		</div>
	</UCard>
</template>
