<script setup lang="ts">
import { computed } from 'vue';
import { route } from 'ziggy-js';
import { useI18n } from 'vue-i18n';
import LazyRunCard from '@/components/Runs/LazyRunCard.vue';
import ActivityContextMenu from '@/components/Groups/Activities/ActivityContextMenu.vue';
import type { ActivityIndexItem } from '@/Types/ActivityCore';
import { groupRunCard } from '@/utils/groupRunCards';
import { sortActivitiesByStart } from '@/utils/activityCalendar';
import { formatCalendarDate } from '@/utils/dateTimeFormat';

const props = defineProps<{
    dateKey: string
    activities: ActivityIndexItem[]
    groupSlug?: string
    canManageActivities?: boolean
}>();

const { t, locale } = useI18n();
const heading = computed(() => t('groups.activities.selected_day.mobile_title', {
    date: formatCalendarDate(locale.value, props.dateKey, { weekday: 'long', day: 'numeric', month: 'long' }),
}));
const runs = computed(() => sortActivitiesByStart(props.activities).map(activity => {
    const groupSlug = activity.group?.slug ?? props.groupSlug ?? '';
    const canManage = activity.group?.can_manage_activities ?? Boolean(props.canManageActivities);
    return {
        activity,
        groupSlug,
        canManage,
        card: {
            ...groupRunCard(activity, activity.group ?? { name: groupSlug, profile_picture_url: null }),
            href: canManage
                ? route('groups.dashboard.activities.show', { group: groupSlug, activity: activity.id })
                : activity.links.view,
        },
    };
}));
</script>

<template>
    <section class="min-w-0 space-y-4">
        <div aria-live="polite" aria-atomic="true">
            <h2 class="text-lg font-semibold text-highlighted">{{ heading }}</h2>
            <p class="mt-1 text-sm text-muted">{{ t('groups.activities.selected_day.mobile_count', { count: runs.length }) }}</p>
        </div>
        <div v-if="runs.length" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <ActivityContextMenu v-for="run in runs" :key="`${dateKey}:${run.activity.id}`"
                :activity="run.activity" :group-slug="run.groupSlug" :can-manage-activities="run.canManage">
                <LazyRunCard :run="run.card" type="date" class="mx-auto" />
            </ActivityContextMenu>
        </div>
        <p v-else class="rounded-xl bg-elevation-1 px-4 py-10 text-center text-sm text-muted dark:bg-elevation-2">
            {{ t('groups.activities.selected_day.empty') }}
        </p>
    </section>
</template>
