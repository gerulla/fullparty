<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupDashboardHeaderGroup } from '@/Types/GroupNavigation'
import GroupHomeNavigation from '@/components/Groups/Dashboard/GroupHomeNavigation.vue'
import GroupNotificationPreferencesModal from '@/components/Groups/GroupNotificationPreferencesModal.vue'
import ReportButton from '@/components/Shared/Reports/ReportButton.vue'
import { useGroupMembershipActions } from '@/composables/useGroupMembershipActions'
import { useGroupHomeNavigation } from '@/composables/useGroupHomeNavigation'
import { groupNotificationIcon } from '@/utils/groupNotifications'

const props = defineProps<{ group: GroupDashboardHeaderGroup; pageTitle?: string }>()
const { t, locale } = useI18n()
const { navigation } = useGroupHomeNavigation(() => props.group)
const { notificationPreferencesOpen, openNotificationPreferences, leaveConfirmationOpen, leaveGroup } =
    useGroupMembershipActions(() => props.group)
</script>

<template>
    <div>
        <div class="relative w-full overflow-hidden bg-elevated">
            <img v-if="group.banner_image_url" :src="group.banner_image_url" alt="" class="absolute inset-0 size-full object-cover">
            <div v-else class="absolute inset-0 bg-gradient-to-br from-brand/15 via-elevated to-brand/5" />
            <div class="absolute inset-0 bg-gradient-to-r from-neutral-950/75 via-neutral-950/35 to-transparent" />
            <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent" />

            <div class="relative flex items-center justify-center px-4 pt-6 sm:px-6 md:min-h-48 md:justify-start md:py-14 lg:px-8">
                <header class="min-w-0 text-center md:text-left">
                    <p class="text-xs uppercase tracking-widest text-white/60">{{ t(`groups.common.group_types.${group.group_type}`) }}</p>
                    <h1 class="mt-1 text-2xl font-bold leading-tight text-white [overflow-wrap:anywhere] md:text-3xl xl:text-4xl">
                        <span>{{ group.name }}</span>
                        <template v-if="pageTitle">
                            <span class="hidden md:inline"> - </span>
                            <span class="mt-1 block text-lg font-medium text-white/80 md:mt-0 md:inline md:text-[length:inherit] md:font-bold md:text-white">{{ pageTitle }}</span>
                        </template>
                    </h1>
                </header>
            </div>
            <div class="relative flex items-start justify-between gap-3 p-4 md:static md:p-0">
                <div class="shrink-0 md:absolute md:right-6 md:top-6 lg:right-8">
                    <ReportButton
                        :target="{ type: 'group', id: group.id, label: group.name }"
                        :aria-label="t('reports.report')"
                        class="bg-neutral-950/75 text-white ring-white/30 backdrop-blur-sm hover:bg-neutral-950 max-md:size-8 max-md:justify-center max-md:gap-0 max-md:[&_[data-slot=label]]:hidden"
                    />
                </div>

                <div class="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-2 md:absolute md:inset-x-0 md:bottom-0 md:p-4 md:px-6 lg:px-8">
                    <UTooltip v-if="group.discord_invite_url" :text="t('groups.dashboard.actions.open_discord')">
                        <UButton
                            :href="group.discord_invite_url" target="_blank" rel="noopener noreferrer"
                            icon="ic:baseline-discord" color="neutral" variant="solid"
                            :aria-label="t('groups.dashboard.actions.open_discord')"
                            class="bg-neutral-950/75 text-white backdrop-blur-sm hover:bg-neutral-950"
                        />
                    </UTooltip>
                    <UTooltip v-if="group.permissions.can_toggle_notifications" :text="t('groups.notifications.preferences.title')">
                        <UButton
                            :icon="groupNotificationIcon(group.notifications)" color="neutral" variant="solid"
                            :aria-label="t('groups.notifications.preferences.title')"
                            class="bg-neutral-950/75 text-white backdrop-blur-sm hover:bg-neutral-950"
                            @click="openNotificationPreferences"
                        />
                    </UTooltip>
                    <UTooltip v-if="group.permissions.can_manage_activities" :text="t('groups.dashboard.actions.open_settings')">
                        <UButton
                            :href="route('groups.dashboard.settings', { group: group.slug, locale }, false)"
                            icon="i-lucide-settings" color="neutral" variant="solid"
                            :aria-label="t('groups.dashboard.actions.open_settings')"
                            class="bg-neutral-950/75 text-white backdrop-blur-sm hover:bg-neutral-950"
                        />
                    </UTooltip>
                    <UButton
                        v-if="group.permissions.can_leave" icon="i-lucide-log-out" color="neutral" variant="solid"
                        :label="t('groups.dashboard.actions.leave_group')" :disabled="leaveConfirmationOpen"
                        class="bg-neutral-950/75 text-white backdrop-blur-sm hover:bg-neutral-950"
                        @click="leaveGroup"
                    />
                </div>
            </div>
        </div>

        <GroupHomeNavigation :items="navigation" />

        <GroupNotificationPreferencesModal
            v-if="group.permissions.can_toggle_notifications"
            v-model:open="notificationPreferencesOpen" :group="group"
        />
    </div>
</template>
