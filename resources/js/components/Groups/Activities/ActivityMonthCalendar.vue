<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import ActivityCalendarDayCell from "@/components/Groups/Activities/ActivityCalendarDayCell.vue";
import type { ActivityIndexItem, GroupQuickCreateShortcut } from "@/Types/ActivityCore";
import {
	buildMonthCalendarDays,
	createMonthStart,
	groupActivitiesByDisplayDate,
	toDisplayDateKey,
} from "@/utils/activityCalendar";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

const props = defineProps<{
	groupSlug: string
	activities: ActivityIndexItem[]
	selectedDateKey?: string | null
	canManageActivities?: boolean
	quickCreateShortcuts: GroupQuickCreateShortcut[]
}>();

const emit = defineEmits<{
	updateSelectedDateKey: [value: string | null]
}>();

const { t, locale } = useI18n();
const { displayTimeZone } = useTimeDisplayMode();

const monthCursor = ref(createMonthStart(new Date()));
const todayKey = computed(() => toDisplayDateKey(new Date(), displayTimeZone.value));

const dayLabels = computed(() => {
	const mondayStart = new Date(2026, 0, 5);

	return Array.from({ length: 7 }, (_, index) => createDateTimeFormatter(locale.value, {
		weekday: 'short',
	}).format(new Date(mondayStart.getFullYear(), mondayStart.getMonth(), mondayStart.getDate() + index)));
});

const monthLabel = computed(() => createDateTimeFormatter(locale.value, {
	month: 'long',
	year: 'numeric',
}).format(monthCursor.value));

const activityMap = computed(() => groupActivitiesByDisplayDate(props.activities, displayTimeZone.value));

const calendarDays = computed(() => buildMonthCalendarDays(activityMap.value, monthCursor.value, todayKey.value));

const visibleMonthActivityCount = computed(() => {
	const targetYear = monthCursor.value.getFullYear();
	const targetMonth = monthCursor.value.getMonth();

	return props.activities.filter((activity) => {
		if (!activity.starts_at) {
			return false;
		}

		const [activityYear, activityMonth] = toDisplayDateKey(new Date(activity.starts_at), displayTimeZone.value)
			.split('-')
			.map(Number);

		return activityYear === targetYear && activityMonth - 1 === targetMonth;
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

			<div class="grid grid-cols-7 gap-px rounded-sm border border-default/70 bg-default/70 overflow-visible">
				<div
					v-for="label in dayLabels"
					:key="label"
					class="bg-muted/20 px-2 py-2 text-center text-xs font-semibold uppercase tracking-wide text-muted"
				>
					{{ label }}
				</div>

				<ActivityCalendarDayCell
					v-for="(day, index) in calendarDays"
					:key="day.key"
					:group-slug="groupSlug"
					:day="day"
					:is-selected="selectedDateKey === day.key"
					:can-manage-activities="canManageActivities"
					:quick-create-shortcuts="quickCreateShortcuts"
					:opens-upward="index >= 35"
					@select="selectDay"
				/>
			</div>
		</div>
	</UCard>
</template>
