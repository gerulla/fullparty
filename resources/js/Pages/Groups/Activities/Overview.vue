<script setup lang="ts">
import RunOverviewLayout from '@/Layouts/RunOverviewLayout.vue'
import ActivityAttendeeRosterBoard from '@/components/Groups/Activities/ActivityAttendeeRosterBoard.vue'
import RunOverviewContent from '@/components/Groups/Activities/RunOverviewContent.vue'
import type { ActivityOverviewPermissions, AttendeeActivity, RunOverviewGroup, RunOverviewViewerParticipation } from '@/Types/ActivityAttendee'

defineOptions({ layout: RunOverviewLayout })

defineProps<{
    group: RunOverviewGroup
    activity: AttendeeActivity
    permissions: ActivityOverviewPermissions
    secretKey?: string | null
    viewer_participation: RunOverviewViewerParticipation | null
}>()
</script>

<template>
    <RunOverviewContent
        :key="activity.id"
        :group="group"
        :activity="activity"
        :permissions="permissions"
        :secret-key="secretKey"
        :viewer-participation="viewer_participation"
    >
        <template #roster="{ activity: displayedActivity }">
            <ActivityAttendeeRosterBoard
                :activity="displayedActivity"
                :group-slug="group.slug"
            />
        </template>
    </RunOverviewContent>
</template>
