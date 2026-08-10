<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import ActivityContextMenu from "@/components/Groups/Activities/ActivityContextMenu.vue";
import ActivityDiscoveryResultItem from "@/components/Groups/Activities/ActivityDiscoveryResultItem.vue";
import ActivityHostGroupBadge from "@/components/Groups/Activities/ActivityHostGroupBadge.vue";
import type { ActivityCalendarDay, ActivityIndexItem } from "@/Types/ActivityCore";
import {
	buildMonthCalendarDays,
	buildWeekCalendarDays,
	createDateFromLocalKey,
	createMonthStart,
	groupActivitiesByDisplayDate,
	sortActivitiesByStart,
	toDisplayDateKey,
	toLocalDateKey,
} from "@/utils/activityCalendar";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import { localizedValue } from "@/utils/localizedValue";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

const props = defineProps<{
	groupSlug?: string
	activities: ActivityIndexItem[]
	canManageActivities?: boolean
	showGroupBadge?: boolean
	discoveryStyle?: boolean
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const { displayTimeZone, withDisplayTimeZone } = useTimeDisplayMode();
const todayKey = computed(() => toDisplayDateKey(new Date(), displayTimeZone.value));
const selectedDateKey = ref(todayKey.value);
const monthCursor = ref(createMonthStart(new Date()));
const isCollapsedToWeek = ref(false);
const renderedCalendarMode = ref<'month' | 'week'>('month');
let collapseTransitionTimeout: ReturnType<typeof setTimeout> | null = null;

onBeforeUnmount(() => {
	if (collapseTransitionTimeout) {
		clearTimeout(collapseTransitionTimeout);
	}
});

watch(todayKey, (nextTodayKey, previousTodayKey) => {
	if (selectedDateKey.value === previousTodayKey) {
		selectedDateKey.value = nextTodayKey;
	}
});

const activityMap = computed(() => groupActivitiesByDisplayDate(props.activities, displayTimeZone.value));
const selectedDate = computed(() => createDateFromLocalKey(selectedDateKey.value));
const monthLabel = computed(() => createDateTimeFormatter(locale.value, {
	month: 'long',
	year: 'numeric',
}).format(monthCursor.value));
const selectedDateLabel = computed(() => {
	if (selectedDateKey.value === todayKey.value) {
		return t('groups.activities.mobile_calendar.today');
	}

	return createDateTimeFormatter(locale.value, {
		weekday: 'short',
		day: 'numeric',
		month: 'short',
	}).format(selectedDate.value);
});
const selectedDateHeadingLabel = computed(() => createDateTimeFormatter(locale.value, {
	weekday: 'long',
	day: 'numeric',
	month: 'long',
}).format(selectedDate.value));
const selectedDateHeading = computed(() => t('groups.activities.selected_day.mobile_title', {
	date: selectedDateHeadingLabel.value,
}));

const dayLabels = computed(() => {
	const mondayStart = new Date(2026, 0, 5);

	return Array.from({ length: 7 }, (_, index) => createDateTimeFormatter(locale.value, {
		weekday: 'narrow',
	}).format(new Date(mondayStart.getFullYear(), mondayStart.getMonth(), mondayStart.getDate() + index)));
});

const monthDays = computed(() => buildMonthCalendarDays(activityMap.value, monthCursor.value, todayKey.value));
const weekDays = computed(() => buildWeekCalendarDays(activityMap.value, selectedDate.value, todayKey.value));
const visibleDays = computed(() => renderedCalendarMode.value === 'week' ? weekDays.value : monthDays.value);
const selectedDateActivities = computed(() => sortActivitiesByStart(activityMap.value[selectedDateKey.value] ?? []));
const selectedDateCountLabel = computed(() => t('groups.activities.selected_day.count', {
	count: selectedDateActivities.value.length,
}));

const activityTypeName = (activity: ActivityIndexItem) => (
	localizedValue(activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| activity.activity_type?.slug
		|| t('groups.activities.cards.unknown_type')
);

const activityTitle = (activity: ActivityIndexItem) => activity.title || activityTypeName(activity);

const activityTargetProgPointLabel = (activity: ActivityIndexItem) => (
	activity.target_prog_point_key
		? localizedValue(activity.target_prog_point_label, locale.value, fallbackLocale.value) || activity.target_prog_point_key
		: null
);

const activityTime = (activity: ActivityIndexItem) => {
	if (!activity.starts_at) {
		return t('groups.activities.cards.no_time');
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		hour: '2-digit',
		minute: '2-digit',
	})).format(new Date(activity.starts_at));
};

const activityMemberCount = (activity: ActivityIndexItem) => t("groups.activities.calendar.member_count", {
	assigned: activity.assigned_slot_count,
	total: activity.slot_count,
});
const activityGroupSlug = (activity: ActivityIndexItem) => activity.group?.slug ?? props.groupSlug ?? '';
const canManageActivity = (activity: ActivityIndexItem) => activity.group?.can_manage_activities ?? Boolean(props.canManageActivities);

const selectDay = (day: ActivityCalendarDay) => {
	selectedDateKey.value = day.key;
	monthCursor.value = createMonthStart(day.date);
};

const moveMonth = (amount: number) => {
	const nextMonth = new Date(monthCursor.value.getFullYear(), monthCursor.value.getMonth() + amount, 1);
	monthCursor.value = nextMonth;
	selectedDateKey.value = toLocalDateKey(nextMonth);
};

const moveWeek = (amount: number) => {
	const current = selectedDate.value;
	const nextDate = new Date(current.getFullYear(), current.getMonth(), current.getDate() + (amount * 7));

	selectedDateKey.value = toLocalDateKey(nextDate);
	monthCursor.value = createMonthStart(nextDate);
};

const moveRange = (amount: number) => {
	if (isCollapsedToWeek.value) {
		moveWeek(amount);
		return;
	}

	moveMonth(amount);
};

const toggleCollapsedMode = () => {
	if (collapseTransitionTimeout) {
		clearTimeout(collapseTransitionTimeout);
		collapseTransitionTimeout = null;
	}

	if (isCollapsedToWeek.value) {
		renderedCalendarMode.value = 'month';
		isCollapsedToWeek.value = false;
		monthCursor.value = createMonthStart(selectedDate.value);

		return;
	}

	isCollapsedToWeek.value = true;
	monthCursor.value = createMonthStart(selectedDate.value);
	collapseTransitionTimeout = setTimeout(() => {
		renderedCalendarMode.value = 'week';
		collapseTransitionTimeout = null;
	}, 300);
};

const goToCreatePage = () => {
	if (!props.groupSlug) {
		return;
	}

	router.get(route('groups.dashboard.activities.create', {
		group: props.groupSlug,
	}));
};

const goToActivity = (activity: ActivityIndexItem) => {
	router.get(route('groups.activities.overview', {
		group: activityGroupSlug(activity),
		activity: activity.id,
	}));
};

const goToManagement = (activity: ActivityIndexItem) => {
	router.get(route('groups.dashboard.activities.show', {
		group: activityGroupSlug(activity),
		activity: activity.id,
	}));
};
</script>

<template>
	<section class="flex flex-col gap-4 xl:hidden">
		<UCard class="overflow-hidden border border-white/10 bg-neutral-950/70" :ui="{ body: 'p-0' }">
			<div class="px-4 pt-4 pb-2">
				<Transition name="calendar-range-header">
					<div v-if="!isCollapsedToWeek" class="flex items-center justify-between gap-3">
						<UButton
							color="neutral"
							variant="ghost"
							icon="i-lucide-chevron-left"
							size="sm"
							:aria-label="t('groups.activities.calendar.previous')"
							@click="moveRange(-1)"
						/>

						<div class="min-w-0 text-center">
							<p class="truncate text-sm font-semibold text-white">
								{{ monthLabel }}
							</p>
							<p class="mt-0.5 text-xs text-white/60">
								{{ selectedDateCountLabel }}
							</p>
						</div>

						<UButton
							color="neutral"
							variant="ghost"
							icon="i-lucide-chevron-right"
							size="sm"
							:aria-label="t('groups.activities.calendar.next')"
							@click="moveRange(1)"
						/>
					</div>
				</Transition>

				<div
					class="overflow-hidden transition-[max-height] duration-300 ease-out"
					:class="isCollapsedToWeek
						? 'max-h-28 sm:max-h-36 md:max-h-44 lg:max-h-48'
						: 'max-h-[23rem] sm:max-h-[36rem] md:max-h-[48rem] lg:max-h-[54rem]'"
				>
					<div
						class="grid grid-cols-7 gap-1 transition-[margin] duration-300 ease-out"
						:class="isCollapsedToWeek ? '' : 'mt-4'"
					>
						<div
							v-for="(label, index) in dayLabels"
							:key="`${label}-${index}`"
							class="text-center text-[0.68rem] font-semibold uppercase text-white/55"
						>
							{{ label }}
						</div>

						<button
							v-for="day in visibleDays"
							:key="day.key"
							type="button"
							class="relative flex aspect-square min-h-10 items-center justify-center text-sm font-semibold transition"
							:class="[
								selectedDateKey === day.key
									? 'bg-white text-brand-700 shadow-lg shadow-brand-950/30'
									: day.isToday
										? 'border border-brand-200/70 text-white'
										: day.activities.length > 0
											? 'bg-white/13 text-white'
											: day.isCurrentMonth
												? 'text-white/78 hover:bg-white/10'
												: 'text-white/35 hover:bg-white/8',
							]"
							@click="selectDay(day)"
						>
							<span>{{ day.date.getDate() }}</span>
							<span
								v-if="day.activities.length > 0"
								class="absolute bottom-1 h-1 w-1 rounded-full"
								:class="selectedDateKey === day.key ? 'bg-brand-600' : 'bg-brand-200'"
							/>
						</button>
					</div>
				</div>

				<button
					type="button"
					class="mx-auto mt-3 flex h-7 w-10 items-center justify-center text-muted transition hover:text-toned focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/60"
					:aria-label="isCollapsedToWeek
						? t('groups.activities.mobile_calendar.expand')
						: t('groups.activities.mobile_calendar.collapse')"
					:title="isCollapsedToWeek
						? t('groups.activities.mobile_calendar.expand')
						: t('groups.activities.mobile_calendar.collapse')"
					@click="toggleCollapsedMode"
				>
					<UIcon
						name="i-lucide-chevron-up"
						class="size-5 transition-transform duration-300 ease-out"
						:class="isCollapsedToWeek ? 'rotate-180' : 'rotate-0'"
					/>
				</button>
			</div>
		</UCard>

		<div class="flex items-start justify-between gap-3 border-b border-white/10 pb-3">
			<div class="min-w-0">
				<p class="text-xs font-semibold uppercase tracking-wide text-brand">
					{{ selectedDateLabel }}
				</p>
				<h2 class="mt-1 truncate text-xl font-semibold text-highlighted">
					{{ selectedDateHeading }}
				</h2>
				<p class="mt-1 text-sm text-muted">
					{{ t('groups.activities.selected_day.mobile_count', { count: selectedDateActivities.length }) }}
				</p>
			</div>

			<UButton
				v-if="canManageActivities && groupSlug"
				class="shrink-0"
				color="neutral"
				icon="i-lucide-plus"
				size="sm"
				:label="t('groups.activities.create.cta')"
				@click="goToCreatePage"
			/>
		</div>

		<div v-if="selectedDateActivities.length > 0" class="flex flex-col gap-3">
			<template v-if="discoveryStyle">
				<ActivityDiscoveryResultItem
					v-for="activity in selectedDateActivities"
					:key="activity.id"
					:activity="activity"
				/>
			</template>
			<template v-else>
				<ActivityContextMenu
				v-for="activity in selectedDateActivities"
				:key="activity.id"
				:group-slug="activityGroupSlug(activity)"
				:can-manage-activities="canManageActivity(activity)"
				:activity="activity"
			>
				<article
					class="relative grid cursor-pointer grid-cols-[4.5rem_minmax(0,1fr)_auto] items-center gap-3 overflow-visible border border-white/10 bg-white/[0.035] px-3 py-3 transition hover:border-brand-400/40 hover:bg-brand-500/10"
					role="button"
					tabindex="0"
					@click="goToActivity(activity)"
					@keydown.enter.prevent="goToActivity(activity)"
					@keydown.space.prevent="goToActivity(activity)"
				>
					<div
						v-if="activity.has_existing_application"
						class="pointer-events-none absolute -left-2 -top-2 z-20 flex h-7 w-7 items-center justify-center"
						:aria-label="t('groups.dashboard.upcoming_runs.view_application')"
						:title="t('groups.dashboard.upcoming_runs.view_application')"
					>
						<UIcon
							name="i-lucide-pin"
							class="h-7 w-7 -rotate-35 text-brand-400 drop-shadow-[0_4px_10px_rgba(168,85,247,0.8)]"
						/>
					</div>

					<div class="border-r border-white/10 pr-3 text-center">
						<p class="text-sm font-semibold text-toned">
							{{ activityTime(activity) }}
						</p>
					</div>

					<div class="min-w-0">
						<div class="flex min-w-0 flex-wrap items-center gap-2">
							<span
								class="h-2.5 w-2.5 shrink-0 rounded-full"
								:class="getActivityStatusMeta(activity.status).dotClass"
							/>
							<h3 class="min-w-0 flex-1 line-clamp-2 text-sm font-semibold text-toned">
								{{ activityTitle(activity) }}
							</h3>
							<UBadge
								v-if="activityTargetProgPointLabel(activity)"
								:label="activityTargetProgPointLabel(activity)"
								color="neutral"
								variant="soft"
								size="md"
							/>
						</div>
						<p class="mt-1 truncate text-xs text-muted">
							{{ activityTypeName(activity) }}
						</p>
						<ActivityHostGroupBadge
							v-if="showGroupBadge"
							class="mt-1"
							:group="activity.group"
						/>
						<p class="mt-1 text-xs text-muted">
							{{ activityMemberCount(activity) }}
						</p>
					</div>

					<div class="flex shrink-0 items-center gap-2">
						<UBadge
							class="hidden sm:inline-flex"
							:label="t(`groups.activities.statuses.${activity.status}`)"
							:color="getActivityStatusMeta(activity.status).color"
							variant="subtle"
						/>
						<UButton
							v-if="canManageActivity(activity)"
							color="neutral"
							variant="ghost"
							icon="i-lucide-settings"
							size="sm"
							:aria-label="t('groups.activities.context_menu.open_management')"
							:title="t('groups.activities.context_menu.open_management')"
							@click.stop="goToManagement(activity)"
						/>
					</div>
				</article>
				</ActivityContextMenu>
			</template>
		</div>

		<div v-else class="border border-dashed border-white/12 bg-white/[0.025] px-4 py-10 text-center text-sm text-muted">
			{{ t('groups.activities.selected_day.empty') }}
		</div>
	</section>
</template>

<style scoped>
.calendar-range-header-enter-active,
.calendar-range-header-leave-active {
	overflow: hidden;
	transition:
		max-height 220ms ease,
		opacity 180ms ease,
		transform 180ms ease;
}

.calendar-range-header-enter-from,
.calendar-range-header-leave-to {
	max-height: 0;
	opacity: 0;
	transform: translateY(-0.25rem);
}

.calendar-range-header-enter-to,
.calendar-range-header-leave-from {
	max-height: 3rem;
	opacity: 1;
	transform: translateY(0);
}
</style>
