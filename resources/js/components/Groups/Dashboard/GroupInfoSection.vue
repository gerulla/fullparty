<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { GroupDashboardGroup } from '@/Types/Groups'
import GroupDiscoveryBadge from '@/components/Groups/GroupDiscoveryBadge.vue'
import { useGroupInfo } from '@/composables/useGroupInfo'

const props = defineProps<{ group: GroupDashboardGroup }>()
const { t } = useI18n()
const { descriptionText, languagesText, primaryBadges, tagBadges, activeDaysText, activeHoursText, activityWindowText, resolveBadgeLabel } =
    useGroupInfo(() => props.group)
</script>

<template>
    <section aria-labelledby="group-info-title" class="min-w-0 overflow-hidden rounded-lg border border-default bg-elevated/20">
        <div class="space-y-4 p-4 sm:p-5">
            <div class="flex items-center justify-between gap-4">
                <div class="flex min-w-0 items-center gap-3">
                    <UAvatar :src="group.profile_picture_url || undefined" :alt="group.name" icon="i-lucide-users-round" size="lg" class="shrink-0" />
                    <h2 id="group-info-title" class="text-lg font-semibold text-highlighted">{{ t('groups.common.labels.about_us') }}</h2>
                </div>
                <UButton v-if="group.discord_invite_url" :href="group.discord_invite_url" target="_blank" rel="noopener noreferrer"
                    icon="ic:baseline-discord" trailing-icon="i-lucide-arrow-up-right" color="neutral" variant="ghost" size="sm"
                    :label="t('groups.common.labels.discord')" class="shrink-0" />
            </div>
            <div class="space-y-3">
                <p class="max-w-4xl whitespace-pre-line text-sm leading-6 text-toned [overflow-wrap:anywhere]">{{ descriptionText }}</p>
                <div v-if="primaryBadges.length || tagBadges.length" class="flex flex-wrap gap-2">
                    <GroupDiscoveryBadge v-for="badge in primaryBadges" :key="`preference:${badge.value}`"
                        :color="badge.color" :label="resolveBadgeLabel(badge.value)" class="max-w-full whitespace-normal! [overflow-wrap:anywhere]" />
                    <GroupDiscoveryBadge v-for="tag in tagBadges" :key="`tag:${tag.value}`" :color="tag.color" :label="tag.label"
                        class="max-w-full whitespace-normal! [overflow-wrap:anywhere]" />
                </div>
            </div>
        </div>

        <dl class="grid min-w-0 grid-cols-2 gap-x-6 gap-y-5 border-t border-default bg-muted/30 p-4 text-sm sm:grid-cols-3 sm:p-5 xl:grid-cols-5">
            <div class="min-w-0">
                <dt class="flex items-center gap-1.5 text-xs text-muted">
                    <UIcon name="i-lucide-user-round" class="size-4 shrink-0" />
                    {{ t('groups.common.labels.owner') }}
                </dt>
                <dd class="mt-1.5 flex items-center gap-2 text-highlighted">
                    <UAvatar :src="group.owner.avatar_url || undefined" icon="i-lucide-user-round" size="2xs" class="shrink-0" />
                    <span class="min-w-0 [overflow-wrap:anywhere]">{{ group.owner.name || t('groups.dashboard.labels.not_available') }}</span>
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="flex items-center gap-1.5 text-xs text-muted">
                    <UIcon name="i-lucide-server" class="size-4 shrink-0" />
                    <span>{{ t('general.datacenter') }}<template v-if="group.region"> / {{ t('groups.common.labels.region') }}</template></span>
                </dt>
                <dd class="mt-1.5 text-highlighted [overflow-wrap:anywhere]">
                    {{ group.datacenter || t('groups.common.states.not_shared') }}
                    <span v-if="group.region" class="text-muted"> · {{ group.region }}</span>
                </dd>
            </div>
            <div v-if="languagesText" class="col-span-2 min-w-0 sm:col-span-1">
                <dt class="flex items-center gap-1.5 text-xs text-muted">
                    <UIcon name="i-lucide-languages" class="size-4 shrink-0" />
                    {{ t('groups.common.labels.languages') }}
                </dt>
                <dd class="mt-1.5 text-highlighted [overflow-wrap:anywhere]">{{ languagesText }}</dd>
            </div>
            <div v-if="activityWindowText" class="col-span-2 min-w-0 sm:col-span-3 xl:col-span-2">
                <dt class="flex items-center gap-1.5 text-xs text-muted">
                    <UIcon name="i-lucide-clock-3" class="size-4 shrink-0" />
                    {{ t('groups.common.labels.activity_window') }}
                </dt>
                <dd class="mt-1.5 space-y-1 [overflow-wrap:anywhere]">
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <span v-if="activeHoursText" class="font-medium text-highlighted">{{ activeHoursText }}</span>
                        <span class="text-xs text-muted">{{ group.active_timezone }}</span>
                    </div>
                    <p class="text-toned">{{ activeDaysText }}</p>
                </dd>
            </div>
        </dl>
    </section>
</template>
