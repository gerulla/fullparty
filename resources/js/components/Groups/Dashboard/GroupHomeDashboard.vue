<script setup lang="ts">
import { useNow } from '@vueuse/core'
import type { GroupDashboardGroup, GroupDashboardView } from '@/Types/Groups'
import GroupInfoSection from '@/components/Groups/Dashboard/GroupInfoSection.vue'
import GroupAppliedRunsSection from '@/components/Groups/Dashboard/GroupAppliedRunsSection.vue'
import GroupHostedRunsSection from '@/components/Groups/Dashboard/GroupHostedRunsSection.vue'
import GroupUpcomingRunsSection from '@/components/Groups/Dashboard/GroupUpcomingRunsSection.vue'
import GroupActivityTypesSection from '@/components/Groups/Dashboard/GroupActivityTypesSection.vue'

withDefaults(defineProps<{ group: GroupDashboardGroup; view?: GroupDashboardView }>(), { view: 'user' })
const now = useNow({ interval: 60_000 })
</script>

<template>
    <div class="space-y-6 p-4 sm:space-y-8 sm:p-6 lg:p-8">
        <GroupInfoSection :group="group" />
        <GroupHostedRunsSection v-if="view === 'staff'" :group="group" />
        <GroupAppliedRunsSection v-else :group="group" :now="now" />
        <GroupUpcomingRunsSection :group="group" :now="now" />
        <GroupActivityTypesSection :items="group.content_items" :group-slug="group.slug" />
    </div>
</template>
