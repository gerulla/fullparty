<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { parseDate } from "@internationalized/date";
import { useI18n } from "vue-i18n";
import ActivityDiscoveryResultItem from "@/components/Groups/Activities/ActivityDiscoveryResultItem.vue";
import type { MyRunsDaySection } from "@/Types/MyRuns";
import { createDateFromLocalKey } from "@/utils/activityCalendar";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";

const props = defineProps<{
	days: MyRunsDaySection[]
	selectedDate: string
	todayDate: string
	participatingIds: number[]
}>();

const { t, locale } = useI18n();
const root = ref<HTMLElement | null>(null);
const tomorrow = computed(() => parseDate(props.todayDate).add({ days: 1 }).toString());
const displayDays = computed(() => props.days.some((day) => day.date === props.selectedDate)
	? props.days
	: [...props.days, { date: props.selectedDate, activities: [] }].sort((a, b) => a.date.localeCompare(b.date)));

const dayHeading = (date: string) => {
	const formatted = createDateTimeFormatter(locale.value, {
		weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
	}).format(createDateFromLocalKey(date));
	const relative = date === props.todayDate ? t('my_runs.results.today')
		: date === tomorrow.value ? t('my_runs.results.tomorrow') : null;
	return relative ? t('my_runs.results.relative_date', { relative, date: formatted }) : formatted;
};

const scrollToDate = async (date: string, animate = true) => {
	await nextTick();
	const container = root.value;
	const target = container?.querySelector<HTMLElement>(`[data-run-date="${date}"]`);
	if (!container || !target) return;
	const behavior = animate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'smooth' : 'auto';
	if (window.matchMedia('(min-width: 1024px)').matches) {
		container.scrollTo({ top: target.getBoundingClientRect().top - container.getBoundingClientRect().top + container.scrollTop, behavior });
	} else if (animate) {
		target.scrollIntoView({ block: 'start', behavior });
	}
};

onMounted(() => { void scrollToDate(props.selectedDate, false); });
watch(() => [props.days, props.selectedDate] as const, ([days, date], [previousDays, previousDate]) => {
	if (days !== previousDays && date === previousDate) void scrollToDate(date, false);
});
defineExpose({ scrollToDate });
</script>

<template>
	<section ref="root" class="min-w-0 space-y-7 px-2 lg:h-full lg:min-h-0 lg:overflow-y-auto lg:pr-3" :aria-label="t('my_runs.tools.results')">
		<section
			v-for="day in displayDays"
			:key="day.date"
			:data-run-date="day.date"
			:aria-labelledby="`my-runs-day-${day.date}`"
			class="scroll-mt-4 border-l-2 pb-1 pl-3 transition-colors"
			:class="day.date === selectedDate ? 'border-brand-400 bg-brand-400/5' : 'border-transparent'"
		>
			<header class="mb-3 flex items-baseline justify-between gap-3 border-b border-default py-2">
				<h2 :id="`my-runs-day-${day.date}`" class="min-w-0 text-sm font-semibold uppercase" :class="day.date === selectedDate ? 'text-brand-300' : 'text-toned'">
					<time :datetime="day.date">{{ dayHeading(day.date) }}</time>
				</h2>
				<span class="shrink-0 text-xs text-muted">{{ t('my_runs.results.run_count', day.activities.length) }}</span>
			</header>
			<div v-if="day.activities.length" class="space-y-4 pt-2">
				<ActivityDiscoveryResultItem
					v-for="activity in day.activities"
					:key="activity.id"
					:activity="activity"
					:participating="participatingIds.includes(activity.id)"
				/>
			</div>
			<p v-else class="py-8 text-center text-sm text-muted" role="status">
				{{ days.length ? t('my_runs.results.empty_day') : t('my_runs.results.empty') }}
			</p>
		</section>
	</section>
</template>
