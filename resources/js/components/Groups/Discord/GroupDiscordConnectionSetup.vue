<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useToast } from '@nuxt/ui/composables'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupDiscordSettingsData, LinkToken } from '../../../Types/GroupDiscord'

const props = defineProps<Pick<GroupDiscordSettingsData, 'group' | 'inviteUrl'>>()
const { t, locale } = useI18n()
const page = usePage()
const toast = useToast()
const linkToken = ref<LinkToken | null>(null)
const generatingToken = ref(false)
const hasActiveToken = computed(() => {
    if (linkToken.value) {
        return true;
    }

    if (!props.group.discord_link_token_expires_at) {
        return false;
    }

    return new Date(props.group.discord_link_token_expires_at).getTime() > Date.now();
});

const tokenExpiresAt = computed(() => linkToken.value?.expires_at ?? props.group.discord_link_token_expires_at);
const generateToken = () => {
    generatingToken.value = true;

    router.post(route("groups.dashboard.discord-integration.link-token", props.group.slug), {}, {
        preserveScroll: true,
        onFinish: () => {
            generatingToken.value = false;
        },
    });
};

const copyToken = async () => {
    if (!linkToken.value) {
        return;
    }

    await navigator.clipboard.writeText(linkToken.value.token);

    toast.add({
        title: t("groups.discord.toasts.token_copied"),
        color: "success",
        icon: "i-lucide-copy-check",
    });
};

watch(
    () => page.props.flash?.data?.discord_guild_link_token,
    (value) => {
        linkToken.value = (value as LinkToken | null) ?? null;
    },
    { immediate: true },
);
</script>

<template>
    <div
        class="flex flex-col gap-4 @4xl:flex-row"
    >
        <UCard class="min-w-0 flex-1 dark:bg-elevated/25">
            <template #header>
                <div class="flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg border border-brand-400/25 bg-brand-500/10 text-brand">
                        <span class="text-sm font-semibold">1</span>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-highlighted">{{ t("groups.discord.setup.invite.title") }}</h2>
                        <p class="text-sm text-muted">{{ t("groups.discord.setup.invite.subtitle") }}</p>
                    </div>
                </div>
            </template>

            <div class="space-y-4 text-sm text-muted">
                <p>{{ t("groups.discord.setup.invite.description") }}</p>
                <UButton
                    :href="inviteUrl"
                    icon="i-lucide-external-link"
                    color="brand"
                    variant="solid"
                >
                    {{ t("groups.discord.actions.invite") }}
                </UButton>
            </div>
        </UCard>

        <UCard class="min-w-0 flex-1 dark:bg-elevated/25">
            <template #header>
                <div class="flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg border border-brand-400/25 bg-brand-500/10 text-brand">
                        <span class="text-sm font-semibold">2</span>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-highlighted">{{ t("groups.discord.setup.link.title") }}</h2>
                        <p class="text-sm text-muted">{{ t("groups.discord.setup.link.subtitle") }}</p>
                    </div>
                </div>
            </template>

            <div class="space-y-4 text-sm text-muted">
                <p>{{ t("groups.discord.setup.link.description") }}</p>
                <UButton
                    icon="i-lucide-key-round"
                    color="neutral"
                    variant="soft"
                    :loading="generatingToken"
                    @click="generateToken"
                >
                    {{ t("groups.discord.actions.generate_token") }}
                </UButton>

                <div
                    v-if="linkToken"
                    class="space-y-3 border border-brand-400/25 bg-brand-500/10 p-4"
                >
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand">{{ t("groups.discord.link.generated_token") }}</p>
                        <p class="mt-1 break-all font-mono text-lg font-semibold text-highlighted">{{ linkToken.token }}</p>
                        <p class="mt-1 text-xs text-muted">{{ t("groups.discord.link.expires_at", { date: new Date(linkToken.expires_at).toLocaleString(locale) }) }}</p>
                    </div>
                    <UButton
                        icon="i-lucide-copy"
                        color="neutral"
                        variant="soft"
                        @click="copyToken"
                    >
                        {{ t("groups.discord.actions.copy_token") }}
                    </UButton>
                </div>

                <UAlert
                    v-else-if="hasActiveToken"
                    color="info"
                    variant="soft"
                    icon="i-lucide-clock"
                    :title="t('groups.discord.link.active_token_title')"
                    :description="t('groups.discord.link.active_token_description', { date: tokenExpiresAt ? new Date(tokenExpiresAt).toLocaleString(locale) : '' })"
                />
            </div>
        </UCard>

        <UCard class="min-w-0 flex-1 dark:bg-elevated/25">
            <template #header>
                <div class="flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg border border-default/60 bg-muted text-muted">
                        <span class="text-sm font-semibold">3</span>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-highlighted">{{ t("groups.discord.setup.complete.title") }}</h2>
                        <p class="text-sm text-muted">{{ t("groups.discord.setup.complete.subtitle") }}</p>
                    </div>
                </div>
            </template>

            <div class="space-y-4 text-sm text-muted">
                <p>{{ t("groups.discord.setup.complete.description") }}</p>
                <UAlert
                    color="neutral"
                    variant="soft"
                    icon="i-lucide-terminal"
                    :title="t('groups.discord.setup.complete.command_title')"
                    :description="t('groups.discord.setup.complete.command_description')"
                />
            </div>
        </UCard>
    </div>
</template>
