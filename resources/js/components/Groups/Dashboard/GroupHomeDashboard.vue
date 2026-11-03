<script setup lang="ts">
import { useNow } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import type { GroupDashboardGroup, GroupDashboardView } from '@/Types/Groups'
import GroupInfoSection from '@/components/Groups/Dashboard/GroupInfoSection.vue'
import GroupAppliedRunsSection from '@/components/Groups/Dashboard/GroupAppliedRunsSection.vue'
import GroupHostedRunsSection from '@/components/Groups/Dashboard/GroupHostedRunsSection.vue'
import GroupUpcomingRunsSection from '@/components/Groups/Dashboard/GroupUpcomingRunsSection.vue'
import GroupActivityTypesSection from '@/components/Groups/Dashboard/GroupActivityTypesSection.vue'
import GroupNotificationPreferencesModal from '@/components/Groups/GroupNotificationPreferencesModal.vue'
import ReportButton from '@/components/Shared/Reports/ReportButton.vue'
import { useGroupMembershipActions } from '@/composables/useGroupMembershipActions'
import { groupNotificationIcon } from '@/utils/groupNotifications'

const props = withDefaults(defineProps<{ group: GroupDashboardGroup; view?: GroupDashboardView }>(), { view: 'user' })
const { t } = useI18n()
const now = useNow({ interval: 60_000 })
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

            <div class="relative flex items-center justify-center px-4 pt-6 sm:min-h-48 sm:justify-start sm:px-6 sm:py-14 lg:px-8">
                <header class="min-w-0 max-w-3xl text-center sm:text-left">
                    <p class="text-xs uppercase tracking-widest text-white/60">{{ t(`groups.common.group_types.${group.group_type}`) }}</p>
                    <h1 class="mt-1 text-2xl font-bold leading-none text-white [overflow-wrap:anywhere] sm:text-4xl">
                        {{ group.name }}
                    </h1>
                </header>
            </div>
            <div class="relative flex items-start justify-between gap-3 p-4 sm:static sm:p-0">
                <div class="shrink-0 sm:absolute sm:right-6 sm:top-6 lg:right-8">
                    <ReportButton
                        :target="{ type: 'group', id: group.id, label: group.name }"
                        class="bg-neutral-950/75 text-white ring-white/30 backdrop-blur-sm hover:bg-neutral-950"
                    />
                </div>

                <div class="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-2 sm:absolute sm:inset-x-0 sm:bottom-0 sm:p-4 sm:px-6 lg:px-8">
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
                    <UButton
                        v-if="group.permissions.can_leave" icon="i-lucide-log-out" color="neutral" variant="solid"
                        :label="t('groups.dashboard.actions.leave_group')" :disabled="leaveConfirmationOpen"
                        class="bg-neutral-950/75 text-white backdrop-blur-sm hover:bg-neutral-950"
                        @click="leaveGroup"
                    />
                </div>
            </div>
        </div>

        <div class="space-y-6 p-4 sm:space-y-8 sm:p-6 lg:p-8">
            <GroupInfoSection :group="group" />
            <GroupHostedRunsSection v-if="view === 'staff'" :group="group" />
            <GroupAppliedRunsSection v-else :group="group" :now="now" />
            <GroupUpcomingRunsSection :group="group" :now="now" />
            <GroupActivityTypesSection :items="group.content_items" :group-slug="group.slug" />
        </div>

        <GroupNotificationPreferencesModal
            v-if="group.permissions.can_toggle_notifications"
            v-model:open="notificationPreferencesOpen" :group="group"
        />
    </div>
</template>
