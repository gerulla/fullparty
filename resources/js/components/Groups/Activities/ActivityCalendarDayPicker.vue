<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { usePreferredReducedMotion } from '@vueuse/core';
import { useI18n } from 'vue-i18n';
import type { ActivityCalendarDay } from '@/Types/ActivityCore';
import { createDateTimeFormatter } from '@/utils/dateTimeFormat';
import { getActivityStatusDotClass } from '@/utils/activityStatusMeta';

const props = defineProps<{
    days: ActivityCalendarDay[]
    selectedDateKey: string
    expanded: boolean
}>();
defineEmits<{
    select: [key: string]
    toggle: []
}>();

const { t, locale } = useI18n();
const reducedMotion = usePreferredReducedMotion();
const rangeViewport = ref<HTMLElement | null>(null);
const rangeContent = ref<HTMLElement | null>(null);
const animating = ref(false);
let heightAnimation: Animation | null = null;
let contentAnimation: Animation | null = null;
let transitionId = 0;

const cancelAnimations = () => {
    heightAnimation?.cancel();
    contentAnimation?.cancel();
    heightAnimation = null;
    contentAnimation = null;
    animating.value = false;
};

watch(() => props.expanded, async (expanded) => {
    const id = ++transitionId;
    const previousHeight = rangeViewport.value?.getBoundingClientRect().height;
    await nextTick();
    if (id !== transitionId) return;
    const viewport = rangeViewport.value;
    const content = rangeContent.value;
    cancelAnimations();
    if (!viewport || !content || previousHeight === undefined || reducedMotion.value === 'reduce') return;

    animating.value = true;
    // Animate the actual row height so the agenda and legend move with the picker.
    const animation = viewport.animate([
        { height: `${previousHeight}px` },
        { height: `${content.getBoundingClientRect().height}px` },
    ], { duration: 320, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' });
    heightAnimation = animation;
    contentAnimation = content.animate([
        { opacity: 0.35, transform: `translateY(${expanded ? -6 : 6}px)` },
        { opacity: 1, transform: 'translateY(0)' },
    ], { duration: 240, easing: 'ease-out' });
    void animation.finished.then(() => {
        if (heightAnimation === animation) cancelAnimations();
    }).catch(() => { /* A newer toggle replaces this animation. */ });
});
watch(reducedMotion, cancelAnimations);
onBeforeUnmount(() => {
    transitionId++;
    cancelAnimations();
});
const weekdayLabels = computed(() => Array.from({ length: 7 }, (_, index) => {
    const date = new Date(2026, 0, 5 + index);
    return {
        short: createDateTimeFormatter(locale.value, { weekday: 'short' }).format(date),
        narrow: createDateTimeFormatter(locale.value, { weekday: 'narrow' }).format(date),
    };
}));
const dateLabel = (day: ActivityCalendarDay) => createDateTimeFormatter(locale.value, { dateStyle: 'full' }).format(day.date);
</script>

<template>
    <div class="space-y-3">
        <div :aria-label="t('groups.activities.selected_day.title')">
            <div class="grid grid-cols-7 gap-1">
                <div v-for="(label, index) in weekdayLabels" :key="index"
                    class="pb-2 text-center text-xs font-medium uppercase text-muted">
                    <span class="sm:hidden">{{ label.narrow }}</span>
                    <span class="hidden sm:inline">{{ label.short }}</span>
                </div>
            </div>
            <div ref="rangeViewport" :class="{ 'overflow-hidden': animating }">
                <div ref="rangeContent" class="grid grid-cols-7 gap-1 py-0.5">
                    <button v-for="day in days" :key="day.key" type="button"
                        class="flex min-h-16 min-w-0 flex-col items-center gap-2 rounded-lg px-1 py-2.5 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                        :class="[
                            selectedDateKey === day.key
                                ? 'bg-brand/10 text-brand ring-1 ring-inset ring-brand'
                                : 'hover:bg-elevation-2 dark:hover:bg-elevation-3',
                            day.isToday && selectedDateKey !== day.key ? 'ring-1 ring-inset ring-brand/50' : '',
                            selectedDateKey !== day.key ? (day.isCurrentMonth ? 'text-highlighted' : 'text-muted') : '',
                        ]"
                        :aria-label="`${dateLabel(day)} · ${t('groups.activities.selected_day.count', { count: day.activities.length })}`"
                        :aria-pressed="selectedDateKey === day.key"
                        :aria-current="day.isToday ? 'date' : undefined"
                        @click="$emit('select', day.key)">
                        <time :datetime="day.key" class="font-semibold tabular-nums">{{ day.date.getDate() }}</time>
                        <span class="flex min-h-1.5 flex-wrap justify-center gap-1" aria-hidden="true">
                            <span v-for="activity in day.activities" :key="activity.id"
                                class="size-1.5 shrink-0 rounded-full" :class="getActivityStatusDotClass(activity.status)"
                                :title="t(`groups.activities.statuses.${activity.status}`)" />
                        </span>
                    </button>
                </div>
            </div>
        </div>
        <div class="flex justify-center border-b border-default/70 pb-3">
            <UButton color="neutral" variant="ghost" size="sm" class="rounded-lg text-muted"
                :label="t(`groups.activities.mobile_calendar.${expanded ? 'collapse' : 'expand'}`)"
                :aria-expanded="expanded" @click="$emit('toggle')">
                <template #leading>
                    <UIcon name="i-lucide-chevron-down" class="size-4 transition-transform duration-300 motion-reduce:transition-none"
                        :class="{ 'rotate-180': expanded }" />
                </template>
            </UButton>
        </div>
    </div>
</template>
