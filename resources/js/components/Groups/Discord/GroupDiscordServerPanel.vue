<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from '@nuxt/ui/composables'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupDiscordSettingsData } from '../../../Types/GroupDiscord'
import { useConfirmationModal } from '@/composables/useConfirmationModal'

const props = defineProps<Pick<GroupDiscordSettingsData, 'group' | 'integration' | 'snapshot' | 'membershipCoverage'> & { preview?: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const toast = useToast()
const confirmationModal = useConfirmationModal()
const botPermissionsLabel = computed(() => {
    const permissions = props.snapshot?.bot_permissions;

    if (Array.isArray(permissions)) {
        return permissions.length > 0 ? permissions.join(", ") : t("groups.discord.stats.none");
    }

    return permissions || t("groups.discord.stats.unknown");
});
const snapshotGuildName = computed(() => props.snapshot?.guild_name ?? props.snapshot?.name ?? props.integration?.name ?? t("groups.discord.status.unknown_guild"));
const snapshotIconUrl = computed(() => props.snapshot?.icon_url ?? props.integration?.icon_url ?? null);
const coverageLoaded = computed(() => props.membershipCoverage !== undefined);
const coverageSource = computed(() => props.membershipCoverage ?? null);
const sanitizeCount = (value: number | null | undefined) => {
    if (typeof value !== "number" || !Number.isFinite(value)) {
        return null;
    }

    return Math.max(Math.floor(value), 0);
};
const formatCount = (value: number | null) => value === null ? "—" : new Intl.NumberFormat().format(value);
const linkedMemberCount = computed(() => sanitizeCount(coverageSource.value?.app_linked_member_count ?? null));
const explicitUnlinkedMemberCount = computed(() => sanitizeCount(coverageSource.value?.unlinked_member_count ?? null));
const totalMemberCount = computed(() => sanitizeCount(coverageSource.value?.member_count ?? null));
const coverageTotal = computed(() => {
    if (totalMemberCount.value !== null) {
        return totalMemberCount.value;
    }

    if (linkedMemberCount.value !== null && explicitUnlinkedMemberCount.value !== null) {
        return linkedMemberCount.value + explicitUnlinkedMemberCount.value;
    }

    return null;
});
const unlinkedMemberCount = computed(() => {
    if (explicitUnlinkedMemberCount.value !== null) {
        return explicitUnlinkedMemberCount.value;
    }

    if (coverageTotal.value !== null && linkedMemberCount.value !== null) {
        return Math.max(coverageTotal.value - linkedMemberCount.value, 0);
    }

    return null;
});
const linkedCoveragePercent = computed(() => {
    if (coverageTotal.value === null || coverageTotal.value <= 0 || linkedMemberCount.value === null) {
        return null;
    }

    return Math.round((linkedMemberCount.value / coverageTotal.value) * 100);
});
const coverageStatsAvailable = computed(() => Boolean(coverageSource.value?.stats_available));
const linkedCoverageLabel = computed(() => linkedCoveragePercent.value === null ? "—" : `${linkedCoveragePercent.value}%`);
const unlinkGuild = async () => {
    if (!props.integration || props.preview) {
        return;
    }

    await confirmationModal.open({
        title: t("groups.discord.unlink_modal.title"),
        description: t("groups.discord.unlink_modal.description", {
            guild: props.integration.name ?? props.integration.discord_guild_id,
        }),
        warningText: t("groups.discord.unlink_modal.warning"),
        severity: "error",
        confirmLabel: t("groups.discord.unlink_modal.confirm"),
        confirmIcon: "i-lucide-unplug",
        onConfirm: async ({ patch }) => {
            patch({ confirmLoading: true });

            return await new Promise<boolean>((resolve) => {
                router.delete(route("groups.dashboard.discord-integration.destroy", props.group.slug), {
                    preserveScroll: true,
                    onSuccess: () => {
                        emit('changed');
                        toast.add({
                            title: t("groups.discord.toasts.unlinked"),
                            color: "success",
                            icon: "i-lucide-unplug",
                        });
                        resolve(true);
                    },
                    onError: () => resolve(false),
                    onFinish: () => patch({ confirmLoading: false }),
                });
            });
        },
    });
};
</script>

<template>
    <div class="grid min-w-0 gap-4 @3xl:grid-cols-2">
        <UCard class="min-w-0 dark:bg-elevated/25">
            <template #header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-highlighted">{{ t('groups.discord.status.title') }}</h3>
                    <UBadge color="success" variant="subtle" class="shrink-0 rounded-md">{{ t('groups.discord.status.linked') }}</UBadge>
                </div>
            </template>
            <div v-if="integration" class="space-y-4">
                <div class="flex min-w-0 items-center gap-3">
                    <UAvatar :src="snapshotIconUrl ?? undefined" :alt="snapshotGuildName" icon="i-lucide-server" size="lg" class="shrink-0 rounded-lg" />
                    <div class="min-w-0">
                        <p class="break-words text-sm font-semibold text-highlighted">{{ snapshotGuildName }}</p>
                        <p class="mt-1 break-all text-xs text-muted tabular-nums">{{ integration.discord_guild_id }}</p>
                    </div>
                </div>
                <div class="space-y-1">
                    <p class="text-xs font-medium text-muted">{{ t('groups.discord.stats.bot_permissions') }}</p>
                    <p class="break-words text-sm text-toned">{{ botPermissionsLabel }}</p>
                </div>
                <UButton color="error" variant="outline" icon="i-lucide-unplug" class="rounded-lg"
                    :label="t('groups.discord.actions.unlink')" :disabled="preview" @click="unlinkGuild" />
            </div>
        </UCard>

        <UCard class="min-w-0 dark:bg-elevated/25">
            <template #header>
                <h3 class="font-semibold text-highlighted">{{ t('groups.discord.stats.title') }}</h3>
            </template>
            <div v-if="!coverageLoaded" class="space-y-4">
                <USkeleton class="h-6 w-44 rounded-md" />
                <USkeleton class="h-2 w-full rounded-full" />
                <div class="grid grid-cols-3 gap-3">
                    <USkeleton v-for="index in 3" :key="index" class="h-14 rounded-lg" />
                </div>
            </div>
            <div v-else class="space-y-4">
                <div class="space-y-2">
                    <p class="text-sm text-muted">
                        <span class="mr-1 text-lg font-semibold text-highlighted tabular-nums">{{ linkedCoverageLabel }}</span>
                        {{ t('groups.discord.stats.available_percent') }}
                    </p>
                    <UProgress :model-value="linkedCoveragePercent ?? 0" :max="100" color="brand"
                        :aria-label="t('groups.discord.stats.available_percent')" :ui="{ base: 'rounded-full', indicator: 'rounded-full' }" />
                </div>
                <dl class="grid grid-cols-3 gap-3">
                    <div>
                        <dt class="text-xs text-muted">{{ t('groups.discord.stats.app_linked') }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-brand tabular-nums">{{ formatCount(linkedMemberCount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">{{ t('groups.discord.stats.not_linked') }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-toned tabular-nums">{{ formatCount(unlinkedMemberCount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">{{ t('groups.discord.stats.members') }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-highlighted tabular-nums">{{ formatCount(coverageTotal) }}</dd>
                    </div>
                </dl>
            </div>
            <UAlert v-if="coverageLoaded && !coverageStatsAvailable" class="mt-4 rounded-lg" color="neutral" variant="soft"
                icon="i-lucide-info" :description="t('groups.discord.stats.coverage_unavailable')" />
            <p class="mt-4 text-xs leading-relaxed text-muted">{{ t('groups.discord.stats.coverage_hint') }}</p>
        </UCard>
    </div>
</template>
