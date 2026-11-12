<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core';
import { useI18n } from 'vue-i18n';
import ActivityCalendarDayCell from '@/components/Groups/Activities/ActivityCalendarDayCell.vue';
import ActivityCalendarStatusLegend from '@/components/Groups/Activities/ActivityCalendarStatusLegend.vue';
import ActivityCalendarRunPreview from '@/components/Groups/Activities/ActivityCalendarRunPreview.vue';
import ActivityCalendarDayPicker from '@/components/Groups/Activities/ActivityCalendarDayPicker.vue';
import ActivityCalendarAgenda from '@/components/Groups/Activities/ActivityCalendarAgenda.vue';
import { useActivityCalendarPreview } from '@/composables/useActivityCalendarPreview';
import type { ActivityIndexItem, GroupQuickCreateShortcut } from '@/Types/ActivityCore';
import {
    buildMonthCalendarDays,
    buildWeekCalendarDays,
    createDateFromLocalKey,
    createMonthStart,
    groupActivitiesByDisplayDate,
    toDisplayDateKey,
    toLocalDateKey,
} from '@/utils/activityCalendar';
import { createDateTimeFormatter } from '@/utils/dateTimeFormat';
import { useTimeDisplayMode } from '@/composables/useTimeDisplayMode';

const props = defineProps<{
    groupSlug?: string
    activities: ActivityIndexItem[]
    selectedDateKey?: string | null
    canManageActivities?: boolean
    quickCreateShortcuts: GroupQuickCreateShortcut[]
    showGroupBadge?: boolean
    groupColors?: Record<number, string>
}>();

const emit = defineEmits<{
    updateSelectedDateKey: [value: string | null]
}>();

const { t, locale } = useI18n();
const { displayTimeZone } = useTimeDisplayMode();
const preview = useActivityCalendarPreview();
const isCompact = useBreakpoints(breakpointsTailwind).smaller('xl');
const currentDisplayDate = () => createDateFromLocalKey(toDisplayDateKey(new Date(), displayTimeZone.value));
const monthCursor = ref(createMonthStart(currentDisplayDate()));
const todayKey = computed(() => toDisplayDateKey(new Date(), displayTimeZone.value));
const agendaDateKey = ref(props.selectedDateKey ?? todayKey.value);
const agendaDate = computed(() => createDateFromLocalKey(agendaDateKey.value));
const monthExpanded = ref(false);

const dayLabels = computed(() => Array.from({ length: 7 }, (_, index) => {
    const date = new Date(2026, 0, 5 + index);
    return {
        short: createDateTimeFormatter(locale.value, { weekday: 'short' }).format(date),
        narrow: createDateTimeFormatter(locale.value, { weekday: 'narrow' }).format(date),
    };
}));

const monthLabel = computed(() => createDateTimeFormatter(locale.value, {
    month: 'long', year: 'numeric',
}).format(monthCursor.value));
const compactMonthLabel = computed(() => createDateTimeFormatter(locale.value, {
    month: 'short', year: 'numeric',
}).format(monthCursor.value));
const activityMap = computed(() => groupActivitiesByDisplayDate(props.activities, displayTimeZone.value));
const calendarDays = computed(() => buildMonthCalendarDays(activityMap.value, monthCursor.value, todayKey.value));
const pickerDays = computed(() => monthExpanded.value
    ? calendarDays.value
    : buildWeekCalendarDays(activityMap.value, agendaDate.value, todayKey.value));
const selectedActivities = computed(() => activityMap.value[agendaDateKey.value] ?? []);
watch(calendarDays, preview.close);

const selectAgendaDay = (dayKey: string) => {
    agendaDateKey.value = dayKey;
    monthCursor.value = createMonthStart(createDateFromLocalKey(dayKey));
    emit('updateSelectedDateKey', dayKey);
};

watch(todayKey, (today, previousToday) => {
    if (agendaDateKey.value !== previousToday) return;
    if (isCompact.value) selectAgendaDay(today);
    else agendaDateKey.value = today;
});
watch(isCompact, (compact) => {
    preview.close();
    if (compact) {
        selectAgendaDay(props.selectedDateKey ?? toLocalDateKey(
            createMonthStart(agendaDate.value).getTime() === monthCursor.value.getTime()
                ? agendaDate.value : monthCursor.value,
        ));
    }
});

const changeRange = (offset: number) => {
    if (isCompact.value && !monthExpanded.value) {
        const selected = agendaDate.value;
        selectAgendaDay(toLocalDateKey(new Date(selected.getFullYear(), selected.getMonth(), selected.getDate() + offset * 7)));
        return;
    }
    monthCursor.value = new Date(monthCursor.value.getFullYear(), monthCursor.value.getMonth() + offset, 1);
    if (isCompact.value) {
        const lastDay = new Date(monthCursor.value.getFullYear(), monthCursor.value.getMonth() + 1, 0).getDate();
        selectAgendaDay(toLocalDateKey(new Date(monthCursor.value.getFullYear(), monthCursor.value.getMonth(), Math.min(agendaDate.value.getDate(), lastDay))));
        return;
    }
    emit('updateSelectedDateKey', null);
};

const goToToday = () => {
    selectAgendaDay(toDisplayDateKey(new Date(), displayTimeZone.value));
};

const selectDay = (dayKey: string) => {
    emit('updateSelectedDateKey', props.selectedDateKey === dayKey ? null : dayKey);
};
</script>

<template>
    <section class="run-month-calendar min-w-0 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 sm:flex-nowrap sm:gap-4">
            <slot name="header-actions" />
            <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                <UButton
                    color="neutral"
                    variant="ghost"
                    icon="i-lucide-chevron-left"
                    class="rounded-lg"
                    :aria-label="t('groups.activities.calendar.previous')"
                    @click="changeRange(-1)"
                />
                <p class="min-w-24 text-center text-sm font-semibold text-highlighted sm:min-w-40 sm:text-lg" aria-live="polite" aria-atomic="true">
                    <span class="sm:hidden">{{ compactMonthLabel }}</span>
                    <span class="hidden sm:inline">{{ monthLabel }}</span>
                </p>
                <UButton
                    color="neutral"
                    variant="ghost"
                    icon="i-lucide-chevron-right"
                    class="rounded-lg"
                    :aria-label="t('groups.activities.calendar.next')"
                    @click="changeRange(1)"
                />
                <UButton
                    color="neutral"
                    variant="soft"
                    class="ml-1 rounded-lg sm:ml-2"
                    :label="t('groups.activities.mobile_calendar.today')"
                    @click="goToToday"
                />
            </div>
        </div>

        <template v-if="isCompact">
            <ActivityCalendarDayPicker :days="pickerDays" :selected-date-key="agendaDateKey"
                :expanded="monthExpanded" @select="selectAgendaDay" @toggle="monthExpanded = !monthExpanded" />
            <ActivityCalendarAgenda :date-key="agendaDateKey" :activities="selectedActivities"
                :group-slug="groupSlug" :can-manage-activities="canManageActivities" />
        </template>
        <div v-else class="grid auto-rows-max grid-cols-7 content-start">
            <div
                v-for="(label, index) in dayLabels"
                :key="index"
                class="border-b border-default/70 px-1 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted sm:px-2"
            >
                <span class="sm:hidden">{{ label.narrow }}</span>
                <span class="hidden sm:inline">{{ label.short }}</span>
            </div>
            <ActivityCalendarDayCell
                v-for="day in calendarDays"
                :key="day.key"
                :group-slug="groupSlug"
                :day="day"
                :is-selected="selectedDateKey === day.key"
                :can-manage-activities="canManageActivities"
                :quick-create-shortcuts="quickCreateShortcuts"
                :group-colors="groupColors"
                expand-selected
                @select="selectDay"
                @preview="preview.show"
                @leave-preview="preview.scheduleClose"
                @close-preview="preview.close"
            />
        </div>

        <ActivityCalendarStatusLegend :dots="isCompact" />
        <ActivityCalendarRunPreview
            v-if="!isCompact && preview.activity.value && preview.reference.value"
            :key="preview.activity.value.id"
            :activity="preview.activity.value"
            :reference="preview.reference.value"
            :open="preview.open.value"
            :focus-content="preview.focusContent.value"
            @keep-open="preview.keepOpen"
            @leave="preview.scheduleClose"
            @close="preview.close"
        />
    </section>
</template>

<style scoped>
.run-month-calendar {
    --ui-primary: var(--color-brand-700);
    --ui-brand: var(--color-brand-700);
}

.dark .run-month-calendar {
    --ui-primary: var(--color-brand-400);
    --ui-brand: var(--color-brand-400);
}
</style>
