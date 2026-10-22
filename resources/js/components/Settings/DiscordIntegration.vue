<script setup lang="ts">
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import type { SettingsUser } from "@/Types/Settings";
import { router, usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { computed, ref, watch } from "vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

const props = defineProps<{ user: SettingsUser }>();
const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const confirmationModal = useConfirmationModal();
const linkToken = ref<{ token: string; expires_at: string } | null>(null);
const generatingLinkToken = ref(false);
const benefits = [
    { key: 'reminders', icon: 'i-lucide-clock-3' },
    { key: 'applications', icon: 'i-lucide-calendar-check' },
    { key: 'identity', icon: 'i-lucide-user-check' },
];
const hasDiscordIntegration = computed(() => props.user.discord_user_integration !== null);
const hasActiveLinkToken = computed(() => {
	if (linkToken.value) {
		return true;
	}

	if (!props.user.discord_link_token_expires_at) {
		return false;
	}

	return new Date(props.user.discord_link_token_expires_at).getTime() > Date.now();
});
const linkTokenExpiresAt = computed(() => linkToken.value?.expires_at ?? props.user.discord_link_token_expires_at);

const disconnectDiscord = async () => {
	await confirmationModal.open({
		title: t("settings.notifications.disconnect_discord_modal.title"),
		description: t("settings.notifications.disconnect_discord_modal.description"),
		severity: "warning",
		warningText: t("settings.notifications.disconnect_discord_modal.warning"),
		confirmLabel: t("settings.notifications.disconnect_discord_modal.confirm"),
		confirmIcon: "i-lucide-unplug",
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });

			return await new Promise<boolean>((resolve) => {
				router.delete(route("settings.discord-integration.destroy"), {
					preserveScroll: true,
					onSuccess: () => resolve(true),
					onError: () => resolve(false),
					onFinish: () => patch({ confirmLoading: false }),
				});
			});
		},
	});
};

const generateDiscordLinkToken = () => {
	generatingLinkToken.value = true;

	router.post(route("settings.discord-integration.link-token"), {}, {
		preserveScroll: true,
		onFinish: () => {
			generatingLinkToken.value = false;
		},
	});
};

const copyDiscordLinkToken = async () => {
	if (!linkToken.value) {
		return;
	}

	await navigator.clipboard.writeText(linkToken.value.token);

	toast.add({
		title: t("settings.notifications.discord_link_token_copied"),
		color: "success",
		icon: "i-lucide-copy-check",
	});
};

watch(
	() => page.props.flash?.data?.discord_user_link_token,
	(value) => {
		linkToken.value = (value as { token: string; expires_at: string } | null) ?? null;
	},
	{ immediate: true },
);

</script>

<template>
    <UCard class="w-full bg-elevation-1 dark:bg-elevation-2">
        <div class="space-y-6">
            <header class="space-y-1">
                <h3 class="font-semibold text-highlighted">{{ t('settings.discord_integration.title') }}</h3>
                <p class="text-sm leading-relaxed text-muted">{{ t('settings.discord_integration.description') }}</p>
            </header>

            <div class="flex flex-col gap-4 rounded-lg bg-elevation-3 p-4 2xl:flex-row 2xl:items-center 2xl:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <UIcon name="ic:baseline-discord" class="size-8 shrink-0 text-brand" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold text-highlighted">
                                {{ hasDiscordIntegration ? t('settings.notifications.discord_app_connected_title') : t('settings.notifications.discord_app_not_connected_title') }}
                            </p>
                            <UBadge :color="hasDiscordIntegration ? 'success' : 'neutral'" variant="soft" size="sm"
                                :label="hasDiscordIntegration ? t('settings.connected') : t('settings.not_connected')" />
                        </div>
                        <p class="mt-1 text-sm text-muted">
                            {{ hasDiscordIntegration ? t('settings.notifications.discord_app_connected_description') : t('settings.notifications.discord_app_not_connected_description') }}
                        </p>
                        <p v-if="hasDiscordIntegration && user.discord_user_integration" class="mt-2 truncate text-sm text-toned">
                            {{ user.discord_user_integration.global_name || user.discord_user_integration.username || user.discord_user_integration.discord_user_id }}
                        </p>
                    </div>
                </div>
                <UButton v-if="!hasDiscordIntegration" :to="route('discord-app.user.redirect')" color="brand"
                    icon="ic:baseline-discord" class="shrink-0 self-start"
                    :label="t('settings.notifications.connect_discord_app')" />
                <UButton v-else color="error" variant="subtle" icon="i-lucide-unplug" class="shrink-0 self-start"
                    :label="t('settings.notifications.disconnect_discord')" @click="disconnectDiscord" />
            </div>

            <section class="grid gap-5 sm:grid-cols-2 2xl:grid-cols-3">
                <div v-for="benefit in benefits" :key="benefit.key" class="flex items-start gap-3">
                    <UIcon :name="benefit.icon" class="mt-0.5 size-5 shrink-0 text-brand" />
                    <div>
                        <h3 class="text-sm font-semibold text-highlighted">{{ t('settings.discord_integration.benefits.' + benefit.key + '.title') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-muted">{{ t('settings.discord_integration.benefits.' + benefit.key + '.description') }}</p>
                    </div>
                </div>
            </section>

            <section class="border-t border-default pt-6">
                <h3 class="mb-4 font-semibold text-highlighted">{{ t('settings.discord_integration.difference_title') }}</h3>
                <div class="grid gap-5 md:grid-cols-2">
                    <div class="space-y-2">
                        <h4 class="flex items-center gap-2 text-sm font-semibold text-toned">
                            <UIcon name="i-lucide-log-in" class="size-4 shrink-0" />
                            {{ t('settings.discord_integration.login_title') }}
                        </h4>
                        <p class="text-sm leading-relaxed text-muted">{{ t('settings.discord_integration.login_description') }}</p>
                        <UButton :to="route('settings', { locale, section: 'socials' })" color="brand" variant="link" class="p-0"
                            :label="t('settings.discord_integration.manage_login')" trailing-icon="i-lucide-arrow-right" />
                    </div>
                    <div class="space-y-2">
                        <h4 class="flex items-center gap-2 text-sm font-semibold text-toned">
                            <UIcon name="ic:baseline-discord" class="size-4 shrink-0" />
                            {{ t('settings.discord_integration.app_title') }}
                        </h4>
                        <p class="text-sm leading-relaxed text-muted">{{ t('settings.discord_integration.app_description') }}</p>
                    </div>
                </div>
                <p class="mt-4 text-sm leading-relaxed text-muted">{{ t('settings.discord_integration.personal_connection') }}</p>
            </section>

            <section class="border-t border-default pt-6">
                <h3 class="mb-4 font-semibold text-highlighted">{{ t('settings.discord_integration.how_title') }}</h3>
                <ol class="space-y-3">
                    <li v-for="(step, index) in ['connect', 'authorize', 'preferences']" :key="step" class="flex items-start gap-3">
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand/10 text-xs font-semibold text-brand" aria-hidden="true">{{ index + 1 }}</span>
                        <p class="pt-0.5 text-sm leading-relaxed text-muted">{{ t('settings.discord_integration.steps.' + step) }}</p>
                    </li>
                </ol>
                <UButton :to="route('settings', { locale, section: 'notifications' })" color="brand" variant="ghost"
                    icon="i-lucide-bell" class="mt-4" :label="t('settings.discord_integration.manage_notifications')" />
            </section>

            <section v-if="!hasDiscordIntegration" class="border-t border-default pt-6">
                <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-highlighted">{{ t('settings.discord_integration.token_title') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-muted">{{ t('settings.discord_integration.token_description') }}</p>
                    </div>
                    <UButton color="neutral" variant="subtle" icon="i-lucide-key-round" class="shrink-0"
                        :loading="generatingLinkToken" :label="t('settings.notifications.generate_discord_link_token')" @click="generateDiscordLinkToken" />
                </div>
                <div v-if="linkToken || hasActiveLinkToken" class="mt-4 flex flex-col gap-3 rounded-lg bg-elevation-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-toned">{{ t('settings.notifications.discord_link_token_title') }}</p>
                        <p v-if="linkToken" class="mt-1 break-all font-mono text-base font-semibold text-highlighted">{{ linkToken.token }}</p>
                        <p class="mt-1 text-xs text-muted">
                            {{ t(linkToken ? 'settings.notifications.discord_link_token_expires' : 'settings.notifications.discord_link_token_active',
                                { date: linkTokenExpiresAt ? new Date(linkTokenExpiresAt).toLocaleString(locale) : '' }) }}
                        </p>
                    </div>
                    <UButton v-if="linkToken" color="neutral" variant="soft" icon="i-lucide-copy" class="shrink-0 self-start"
                        :label="t('settings.notifications.copy_discord_link_token')" @click="copyDiscordLinkToken" />
                </div>
            </section>

            <p class="border-t border-default pt-4 text-sm leading-relaxed text-muted">{{ t('settings.discord_integration.disconnect_description') }}</p>
        </div>
    </UCard>
</template>
