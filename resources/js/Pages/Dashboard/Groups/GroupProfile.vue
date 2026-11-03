<!-- Preserved dashboard homepage for the future group browsing/sharing profile. -->
<script setup lang="ts">
import type { GroupDashboardGroup } from "@/Types/Groups";
import GroupDashboardBanner from "@/components/Groups/GroupDashboardBanner.vue";
import GroupDashboardInfoCard from "@/components/Groups/GroupDashboardInfoCard.vue";
import GroupDashboardUpcomingRunsSection from "@/components/Groups/GroupDashboardUpcomingRunsSection.vue";
import GroupDashboardWeekOverviewSection from "@/components/Groups/GroupDashboardWeekOverviewSection.vue";
import GroupDashboardContentSection from "@/components/Groups/GroupDashboardContentSection.vue";

defineProps<{
    group: GroupDashboardGroup
}>();
</script>

<template>
    <div class="w-full">
        <GroupDashboardBanner :group="group" />
        <div class="flex flex-col gap-6 px-1 sm:px-3 xl:flex-row xl:gap-0 xl:pl-4 xl:pr-0">
            <GroupDashboardInfoCard
                class="-mt-20 mx-auto w-[calc(100%-0.5rem)] max-w-md sm:-mt-24 sm:w-full lg:-mt-28 xl:mx-0 xl:w-full xl:pl-8"
                :group="group"
            />

            <div class="overflow-hidden xl:min-w-0 xl:flex-1">
                <GroupDashboardUpcomingRunsSection :activities="group.upcoming_activities" />
            </div>
        </div>
        <div class="w-full flex flex-col gap-4">
            <GroupDashboardWeekOverviewSection
                :activities="group.current_week_activities"
                :week-start-date="group.current_week.start_date"
                :week-end-date="group.current_week.end_date"
            />
            <GroupDashboardContentSection
                :summary="group.content_summary"
                :items="group.content_items"
            />
        </div>
    </div>
</template>
