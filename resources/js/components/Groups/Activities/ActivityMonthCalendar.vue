<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import ActivityCalendarDayCell from "@/components/Groups/Activities/ActivityCalendarDayCell.vue";
import type { ActivityIndexItem } from "@/components/Groups/Activities/types";

const props = defineProps<{
	activities: ActivityIndexItem[]
	selectedDateKey?: string | null
}>();

const emit = defineEmits<{
	updateSelectedDateKey: [value: string | null]
}>();

const { t, locale } = useI18n();

const createMonthStart = (date: Date) => new Date(date.getFullYear(), date.getMonth(), 1);
const monthCursor = ref(createMonthStart(new Date()));

const toLocalDateKey = (date: Date) => {
	const year = date.getFullYear();
	const month = `${date.getMonth() + 1}`.padStart(2, '0');
	const day = `${date.getDate()}`.padStart(2, '0');

	return `${year}-${month}-${day}`;
};

const dayLabels = computed(() => {
	const mondayStart = new Date(2026, 0, 5);

	return Array.from({ length: 7 }, (_, index) => new Intl.DateTimeFormat(locale.value, {
		weekday: 'short',
	}).format(new Date(mondayStart.getFullYear(), mondayStart.getMonth(), mondayStart.getDate() + index)));
});

const monthLabel = computed(() => new Intl.DateTimeFormat(locale.value, {
	month: 'long',
	year: 'numeric',
}).format(monthCursor.value));

const activityMap = computed(() => {
	return props.activities.reduce<Record<string, ActivityIndexItem[]>>((map, activity) => {
		if (!activity.starts_at) {
			return map;
		}

		const key = toLocalDateKey(new Date(activity.starts_at));
		map[key] ??= [];
		map[key].push(activity);

		return map;
	}, {});
});

const calendarDays = computed(() => {
	const monthStart = createMonthStart(monthCursor.value);
	const startOffset = (monthStart.getDay() + 6) % 7;
	const rangeStart = new Date(monthStart.getFullYear(), monthStart.getMonth(), 1 - startOffset);
	const todayKey = toLocalDateKey(new Date());

	return Array.from({ length: 42 }, (_, index) => {
		const date = new Date(rangeStart.getFullYear(), rangeStart.getMonth(), rangeStart.getDate() + index);
		const key = toLocalDateKey(date);

		return {
			key,
			date,
			isCurrentMonth: date.getMonth() === monthStart.getMonth(),
			isToday: key === todayKey,
			activities: (activityMap.value[key] ?? []).slice().sort((left, right) => {
				return new Date(left.starts_at ?? 0).getTime() - new Date(right.starts_at ?? 0).getTime();
			}),
		};
	});
});

const visibleMonthActivityCount = computed(() => {
	const targetYear = monthCursor.value.getFullYear();
	const targetMonth = monthCursor.value.getMonth();

	return props.activities.filter((activity) => {
		if (!activity.starts_at) {
			return false;
		}

		const date = new Date(activity.starts_at);

		return date.getFullYear() === targetYear && date.getMonth() === targetMonth;
	}).length;
});

const goToPreviousMonth = () => {
	monthCursor.value = new Date(monthCursor.value.getFullYear(), monthCursor.value.getMonth() - 1, 1);
};

const goToNextMonth = () => {
	monthCursor.value = new Date(monthCursor.value.getFullYear(), monthCursor.value.getMonth() + 1, 1);
};

const selectDay = (dayKey: string) => {
	emit('updateSelectedDateKey', props.selectedDateKey === dayKey ? null : dayKey);
};
</script>

<template>
	<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-4' }">
		<template #header>
			<div class="flex items-start justify-between gap-4">
				<div class="flex flex-col gap-1">
					<p class="font-semibold text-md">
						{{ t('groups.activities.calendar.title') }}
					</p>
					<p class="text-sm text-muted">
						{{ t('groups.activities.calendar.subtitle') }}
					</p>
				</div>
				<UBadge
					color="neutral"
					variant="subtle"
					:label="t('groups.activities.calendar.month_count', { count: visibleMonthActivityCount })"
				/>
			</div>
		</template>

		<div class="flex flex-col gap-4">
			<div class="flex items-center justify-between gap-3">
				<UButton
					color="neutral"
					variant="soft"
					icon="i-lucide-chevron-left"
					:label="t('groups.activities.calendar.previous')"
					@click="goToPreviousMonth"
				/>

				<p class="text-lg font-semibold text-toned">
					{{ monthLabel }}
				</p>

				<UButton
					color="neutral"
					variant="soft"
					trailing-icon="i-lucide-chevron-right"
					:label="t('groups.activities.calendar.next')"
					@click="goToNextMonth"
				/>
			</div>

			<div class="grid grid-cols-7 gap-px rounded-sm border border-default/70 bg-default/70 overflow-hidden">
				<div
					v-for="label in dayLabels"
					:key="label"
					class="bg-muted/20 px-2 py-2 text-center text-xs font-semibold uppercase tracking-wide text-muted"
				>
					{{ label }}
				</div>

				<ActivityCalendarDayCell
					v-for="day in calendarDays"
					:key="day.key"
					:day="day"
					:is-selected="selectedDateKey === day.key"
					@select="selectDay"
				/>
			</div>
		</div>
	</UCard>
</template>
