<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from "vue";
import { useI18n } from "vue-i18n";
import { parseDate } from "@internationalized/date";
import type { DateValue } from "@internationalized/date";
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import { useDateTimeFormat } from "@/composables/useDateTimeFormat";
import { useAvailabilityScheduleTime } from "@/composables/useAvailabilityScheduleTime";
import { dateTimeInputInZone } from "@/utils/dateTimeFormat";
import { availabilityDays, availabilityTimelineDay, isPendingAvailabilityException } from "@/utils/availabilityScheduleTime";
import type { GroupAvailabilitySchedulePayload } from "@/Types/Groups";
import type { Cadence, DayKey, AvailabilityStatus, TimeBlock, DaySchedule, WeekSchedule, AvailabilityException, AvailabilityTimelineEntry } from "@/Types/Availability";

const props = defineProps<{
	groupSlug: string
	schedule: GroupAvailabilitySchedulePayload | null
}>();

const { t } = useI18n();
const { formatDateOnly, formatWallTime, displayTimeZone } = useDateTimeFormat();
const toast = useToast();
const isOpen = ref(false);
const isSaving = ref(false);
const cadence = ref<Cadence>(({ 1: "weekly", 2: "biweekly", 4: "monthly" } as const)[props.schedule?.cycle_weeks ?? 1]);
const repeatable = ref(props.schedule?.repeats ?? true);
const lockWeekends = ref(props.schedule?.lock_weekends ?? true);
const onHiatus = ref(props.schedule?.on_hiatus ?? false);
const browserTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
const scheduleTimeZone = ref(props.schedule?.timezone ?? displayTimeZone.value ?? browserTimeZone);
const startsOn = ref(props.schedule?.starts_on ?? dateTimeInputInZone(new Date(), scheduleTimeZone.value).slice(0, 10));
const startsOnCalendarOpen = ref(false);
const startsOnDate = ref<DateValue>(parseDate(startsOn.value));
const addBlockDays = ref<DayKey[]>(Array.from({ length: 4 }, () => "monday"));
const addBlockPopoverOpen = ref<boolean[]>(Array.from({ length: 4 }, () => false));
const exceptionCalendarOpen = ref(false);
const exceptionDate = ref<DateValue | undefined>(undefined);
const copiedDaySchedule = ref<DaySchedule | null>(null);
let nextBlockId = 1;
let nextExceptionId = 1;

const dayKeys = availabilityDays;
const timeTicks = [0, 6, 12, 18, 24];
const statusKeys: AvailabilityStatus[] = ["available", "tentative"];
const dragDraft = ref<{
	weekIndex: number
	day: DayKey
	anchorMinute: number
	currentMinute: number
} | null>(null);
const hoverTime = ref<{
	weekIndex: number
	day: DayKey
	minute: number
	x: number
} | null>(null);
const resizingBlock = ref<{
	weekIndex: number
	day: DayKey
	blockId: number
	edge: "start" | "end"
	originalStart: number
	originalEnd: number
} | null>(null);

const makeBlock = (startsAt: string, endsAt: string, status: AvailabilityStatus): TimeBlock => ({
	id: nextBlockId++,
	starts_at: startsAt,
	ends_at: endsAt,
	status,
});

const makeWeek = (): WeekSchedule => Object.fromEntries(dayKeys.map(day => [
	day,
	{ enabled: false, blocks: [] },
])) as WeekSchedule;

const cloneWeeks = (weeks: WeekSchedule[], renewIds = false): WeekSchedule[] => weeks.map(week => Object.fromEntries(
	dayKeys.map(day => [
		day,
		{
			enabled: week[day].enabled,
			blocks: week[day].blocks.map(block => ({
				...block,
				id: renewIds ? nextBlockId++ : block.id,
			})),
		},
	]),
) as WeekSchedule);

const cloneDaySchedule = (schedule: DaySchedule, renewIds = false): DaySchedule => ({
	enabled: schedule.enabled,
	blocks: schedule.blocks.map(block => ({
		...block,
		id: renewIds ? nextBlockId++ : block.id,
	})),
});

const hydrateWeeks = (): WeekSchedule[] => {
	const weeks = Array.from({ length: 4 }, makeWeek);

	props.schedule?.windows.forEach((window) => {
		const day = dayKeys[window.weekday - 1];
		const week = weeks[window.cycle_week];

		if (!day || !week) {
			return;
		}

		week[day].enabled = true;
		week[day].blocks.push(makeBlock(window.starts_at, window.ends_at, window.status));
	});

	return weeks;
};

const hydrateExceptions = (): AvailabilityException[] => (props.schedule?.exceptions ?? []).map(exception => ({
	id: nextExceptionId++,
	date: exception.date,
	has_time_range: exception.starts_at !== null && exception.ends_at !== null,
	starts_at: exception.starts_at ?? "18:00",
	ends_at: exception.ends_at ?? "22:00",
}));

const savedWeeks = ref<WeekSchedule[]>(hydrateWeeks());
const draftWeeks = ref<WeekSchedule[]>(cloneWeeks(savedWeeks.value));
const savedCadence = ref<Cadence>(cadence.value);
const savedRepeatable = ref(repeatable.value);
const savedLockWeekends = ref(lockWeekends.value);
const savedOnHiatus = ref(onHiatus.value);
const savedStartsOn = ref(startsOn.value);
const savedTimeZone = ref(scheduleTimeZone.value);
const savedExceptions = ref<AvailabilityException[]>(hydrateExceptions());
const draftExceptions = ref<AvailabilityException[]>(savedExceptions.value.map(exception => ({ ...exception })));

const cadenceOptions = computed(() => [
	{ label: t("groups.availability.schedule.cadence.weekly"), value: "weekly" },
	{ label: t("groups.availability.schedule.cadence.biweekly"), value: "biweekly" },
	{ label: t("groups.availability.schedule.cadence.monthly"), value: "monthly" },
]);
const statusOptions = computed(() => statusKeys.map(status => ({
	label: t(`groups.availability.schedule.legend.${status}`),
	value: status,
})));
const dayOptions = computed(() => dayKeys.map(day => ({
	label: t(`groups.availability.schedule.days.${day}`),
	value: day,
})));
const timeZoneOptions = computed(() => {
	const supportedValuesOf = (Intl as typeof Intl & {
		supportedValuesOf?: (key: "timeZone") => string[]
	}).supportedValuesOf;
	const supportedZones = supportedValuesOf ? supportedValuesOf("timeZone") : [browserTimeZone, "UTC"];
	const zones = Array.from(new Set([scheduleTimeZone.value, browserTimeZone, "UTC", ...supportedZones]));

	return zones.map(zone => ({
		label: zone.replaceAll("_", " "),
		value: zone,
	}));
});
const visibleWeekCount = computed(() => ({ weekly: 1, biweekly: 2, monthly: 4 })[cadence.value]);
const visibleWeeks = computed(() => draftWeeks.value.slice(0, visibleWeekCount.value));
const { nowInScheduleZone, minimumExceptionDate, weekRange, dayDate, minutePreview, windowPreview, timeDisplayLabel, displayTimeZoneLabel } = useAvailabilityScheduleTime({
	startsOn, timeZone: scheduleTimeZone, weeks: visibleWeekCount, repeats: repeatable,
});
const futureExceptions = computed(() => {
	return draftExceptions.value
		.filter(exception => isPendingAvailabilityException(exception.date, exception.has_time_range ? exception.starts_at : null, exception.has_time_range ? exception.ends_at : null, nowInScheduleZone.value))
		.sort((left, right) => left.date.localeCompare(right.date));
});

const parseTime = (time: string) => {
	const [hours, minutes] = time.split(":").map(Number);
	return Math.min(24 * 60, Math.max(0, (hours * 60) + minutes));
};

const formatMinute = (minute: number) => {
	const normalizedMinute = minute >= 1440 ? 0 : Math.max(0, minute);

	return `${String(Math.floor(normalizedMinute / 60)).padStart(2, "0")}:${String(normalizedMinute % 60).padStart(2, "0")}`;
};

const quantizeMinute = (minute: number) => Math.min(1440, Math.max(0, Math.round(minute / 15) * 15));

const formatTimeTick = (hour: number) => formatWallTime(`${String(hour).padStart(2, "0")}:00`);

const timelineEntries = computed(() => visibleWeeks.value.map((_, weekIndex) => Object.fromEntries(dayKeys.map((day, dayIndex) => [
	day, availabilityTimelineDay(visibleWeeks.value, startsOn.value, dayDate(weekIndex, dayIndex + 1), repeatable.value),
])) as Record<DayKey, AvailabilityTimelineEntry[]>));
const blockStyle = (entry: AvailabilityTimelineEntry) => ({
	left: `${(entry.startMinute / 1440) * 100}%`,
	width: `${(Math.max(0, entry.endMinute - entry.startMinute) / 1440) * 100}%`,
});

const blockEndMinute = (block: TimeBlock) => {
	const end = parseTime(block.ends_at);
	return end <= parseTime(block.starts_at) ? end + 1440 : end;
};

const hoverTimeStyle = computed(() => {
	if (!hoverTime.value) {
		return {};
	}

	return {
		left: `${hoverTime.value.x}px`,
	};
});

const dragRange = computed(() => {
	const draft = dragDraft.value;

	if (!draft) {
		return null;
	}

	const start = Math.min(draft.anchorMinute, draft.currentMinute);
	const end = Math.max(draft.anchorMinute, draft.currentMinute);

	return {
		start,
		end: Math.max(start + 15, end),
	};
});

const dragPreviewStyle = computed(() => {
	if (!dragRange.value) {
		return {};
	}

	return {
		left: `${(dragRange.value.start / 1440) * 100}%`,
		width: `${((Math.min(1440, dragRange.value.end) - dragRange.value.start) / 1440) * 100}%`,
	};
});

const blockTone = (status: AvailabilityStatus) => ({
	available: "border-success/70 bg-success/45 hover:bg-success/60",
	tentative: "border-primary/70 bg-primary/35 hover:bg-primary/50 dark:bg-primary/45 dark:hover:bg-primary/60",
})[status];

const legendTone = (status: AvailabilityStatus | "unavailable") => ({
	available: "border-success/70 bg-success/45",
	tentative: "border-primary/70 bg-primary/35 dark:bg-primary/45",
	unavailable: "border-default bg-elevation-2 dark:bg-elevation-3",
})[status];

const formattedStartDate = computed(() => formatDateOnly(startsOn.value, { day: "numeric", month: "short", year: "numeric" }));
const formatExceptionDate = (date: string) => formatDateOnly(date, { weekday: "long", day: "numeric", month: "long", year: "numeric" });

const weekLabel = (index: number) => {
	if (cadence.value === "weekly") {
		return t("groups.availability.schedule.weeks.week");
	}

	if (cadence.value === "biweekly") {
		return t(`groups.availability.schedule.weeks.${index === 0 ? "week_a" : "week_b"}`);
	}

	return t("groups.availability.schedule.weeks.numbered", { number: index + 1 });
};

const open = () => {
	cadence.value = savedCadence.value;
	repeatable.value = savedRepeatable.value;
	lockWeekends.value = savedLockWeekends.value;
	onHiatus.value = savedOnHiatus.value;
	scheduleTimeZone.value = props.schedule ? savedTimeZone.value : displayTimeZone.value ?? browserTimeZone;
	startsOn.value = props.schedule ? savedStartsOn.value : dateTimeInputInZone(new Date(), scheduleTimeZone.value).slice(0, 10);
	startsOnDate.value = parseDate(startsOn.value);
	draftWeeks.value = cloneWeeks(savedWeeks.value);
	draftExceptions.value = savedExceptions.value.map(exception => ({ ...exception }));
	exceptionDate.value = undefined;
	copiedDaySchedule.value = null;
	isOpen.value = true;
};

const updateStartsOn = (value: DateValue | undefined) => {
	if (!value) return;

	startsOnDate.value = value;
	startsOn.value = value.toString();
	startsOnCalendarOpen.value = false;
};

const save = () => {
	const windows = visibleWeeks.value.flatMap((week, cycleWeek) => dayKeys.flatMap((day, dayIndex) => (
		week[day].enabled
			? week[day].blocks.map(block => ({
				cycle_week: cycleWeek,
				weekday: dayIndex + 1,
				status: block.status,
				starts_at: block.starts_at,
				ends_at: block.ends_at,
			}))
			: []
	)));
	const exceptions = futureExceptions.value.map(exception => ({
		date: exception.date,
		starts_at: exception.has_time_range ? exception.starts_at : null,
		ends_at: exception.has_time_range ? exception.ends_at : null,
	}));

	isSaving.value = true;

	router.put(route("groups.dashboard.availability.schedule.update", props.groupSlug), {
		cycle_weeks: ({ weekly: 1, biweekly: 2, monthly: 4 } as const)[cadence.value],
		repeats: repeatable.value,
		lock_weekends: lockWeekends.value,
		on_hiatus: onHiatus.value,
		starts_on: startsOn.value,
		timezone: scheduleTimeZone.value,
		windows,
		exceptions,
	}, {
		preserveScroll: true,
		onSuccess: () => {
			savedCadence.value = cadence.value;
			savedRepeatable.value = repeatable.value;
			savedLockWeekends.value = lockWeekends.value;
			savedOnHiatus.value = onHiatus.value;
			savedStartsOn.value = startsOn.value;
			savedTimeZone.value = scheduleTimeZone.value;
			savedWeeks.value = cloneWeeks(draftWeeks.value);
			savedExceptions.value = draftExceptions.value.map(exception => ({ ...exception }));
			isOpen.value = false;
			toast.add({
				title: t("groups.availability.schedule.saved"),
				color: "success",
				icon: "i-lucide-check",
			});
		},
		onError: () => {
			toast.add({
				title: t("groups.availability.schedule.save_error"),
				color: "error",
				icon: "i-lucide-triangle-alert",
			});
		},
		onFinish: () => {
			isSaving.value = false;
		},
	});
};

const toggleDay = (week: WeekSchedule, day: DayKey) => {
	if (week[day].enabled && week[day].blocks.length === 0) {
		week[day].blocks.push(makeBlock("18:00", "22:00", "available"));
	}
};

const addBlock = (schedule: DaySchedule) => {
	schedule.enabled = true;
	schedule.blocks.push(makeBlock("18:00", "22:00", "available"));
};

const addBlockToWeek = (week: WeekSchedule, weekIndex: number) => {
	addBlock(week[addBlockDays.value[weekIndex]]);
	addBlockPopoverOpen.value[weekIndex] = false;
};

const copyDay = (schedule: DaySchedule) => {
	copiedDaySchedule.value = cloneDaySchedule(schedule);
	toast.add({
		title: t("groups.availability.schedule.copy_day_success"),
		color: "success",
		icon: "i-lucide-copy-check",
	});
};

const pasteDay = (week: WeekSchedule, day: DayKey) => {
	if (!copiedDaySchedule.value || isWeekendLocked(day)) {
		return;
	}

	week[day] = cloneDaySchedule(copiedDaySchedule.value, true);
	toast.add({
		title: t("groups.availability.schedule.paste_day_success"),
		color: "success",
		icon: "i-lucide-clipboard-check",
	});
};

const removeBlock = (schedule: DaySchedule, blockId: number) => {
	schedule.blocks = schedule.blocks.filter(block => block.id !== blockId);

	if (schedule.blocks.length === 0) {
		schedule.enabled = false;
	}
};

const minuteFromPointer = (event: PointerEvent, target: HTMLElement) => {
	const rect = target.getBoundingClientRect();
	const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));

	return quantizeMinute(ratio * 1440);
};

const isWeekendLocked = (day: DayKey) => lockWeekends.value && (day === "saturday" || day === "sunday");

const overlapsExistingBlock = (
	schedule: DaySchedule,
	startMinute: number,
	endMinute: number,
	ignoredBlockId: number | null = null,
) => schedule.blocks.some((block) => {
	if (block.id === ignoredBlockId) {
		return false;
	}

	const blockStart = parseTime(block.starts_at);
	const blockEnd = blockEndMinute(block);

	return startMinute < blockEnd && endMinute > blockStart;
});

const capDragMinuteBeforeOverlap = (schedule: DaySchedule, anchorMinute: number, desiredMinute: number) => {
	if (desiredMinute === anchorMinute) {
		return desiredMinute;
	}

	if (desiredMinute > anchorMinute) {
		const nextBlockStart = schedule.blocks
			.map(block => parseTime(block.starts_at))
			.filter(start => start >= anchorMinute)
			.sort((left, right) => left - right)[0];

		return nextBlockStart === undefined
			? desiredMinute
			: Math.min(desiredMinute, nextBlockStart);
	}

	const previousBlockEnd = schedule.blocks
		.map(blockEndMinute)
		.filter(end => end <= anchorMinute)
		.sort((left, right) => right - left)[0];

	return previousBlockEnd === undefined
		? desiredMinute
		: Math.max(desiredMinute, previousBlockEnd);
};

const capResizeMinuteBeforeOverlap = (
	schedule: DaySchedule,
	block: TimeBlock,
	edge: "start" | "end",
	desiredMinute: number,
) => {
	if (edge === "start") {
		const blockEnd = blockEndMinute(block);
		const previousBlockEnd = schedule.blocks
			.filter(candidate => candidate.id !== block.id)
			.map(blockEndMinute)
			.filter(end => end <= blockEnd)
			.sort((left, right) => right - left)[0];

		return Math.max(desiredMinute, previousBlockEnd ?? 0);
	}

	const blockStart = parseTime(block.starts_at);
	const nextBlockStart = schedule.blocks
		.filter(candidate => candidate.id !== block.id)
		.map(candidate => parseTime(candidate.starts_at))
		.filter(start => start >= blockStart)
		.sort((left, right) => left - right)[0];

	return Math.min(desiredMinute, nextBlockStart ?? 1440);
};

const updateHoverTime = (weekIndex: number, day: DayKey, event: PointerEvent) => {
	const target = event.currentTarget as HTMLElement;
	const minute = minuteFromPointer(event, target);
	const rect = target.getBoundingClientRect();

	hoverTime.value = {
		weekIndex,
		day,
		minute,
		x: Math.min(rect.width, Math.max(0, event.clientX - rect.left)),
	};
};

const clearHoverTime = () => {
	if (!dragDraft.value && !resizingBlock.value) {
		hoverTime.value = null;
	}
};

const beginBlockDrag = (weekIndex: number, day: DayKey, event: PointerEvent) => {
	if (event.button !== 0 || isWeekendLocked(day) || resizingBlock.value) {
		return;
	}

	const target = event.currentTarget as HTMLElement;
	const minute = minuteFromPointer(event, target);

	event.preventDefault();
	target.setPointerCapture(event.pointerId);
	dragDraft.value = {
		weekIndex,
		day,
		anchorMinute: minute,
		currentMinute: minute,
	};
};

const moveBlockDrag = (event: PointerEvent) => {
	const draft = dragDraft.value;

	if (!draft) {
		return;
	}

	const schedule = draftWeeks.value[draft.weekIndex]?.[draft.day];
	const desiredMinute = minuteFromPointer(event, event.currentTarget as HTMLElement);

	dragDraft.value.currentMinute = schedule
		? capDragMinuteBeforeOverlap(schedule, draft.anchorMinute, desiredMinute)
		: desiredMinute;
};

const moveTimelinePointer = (weekIndex: number, day: DayKey, event: PointerEvent) => {
	updateHoverTime(weekIndex, day, event);
	moveBlockDrag(event);
	resizeBlock(event);
};

const finishBlockDrag = () => {
	const draft = dragDraft.value;
	const range = dragRange.value;

	dragDraft.value = null;

	if (!draft || !range) {
		return;
	}

	const schedule = draftWeeks.value[draft.weekIndex]?.[draft.day];

	if (!schedule) {
		return;
	}

	if (Math.abs(draft.anchorMinute - draft.currentMinute) < 15 || range.start >= 1440) {
		return;
	}

	const end = Math.min(1440, Math.max(range.start + 30, range.end));

	if (overlapsExistingBlock(schedule, range.start, end)) {
		return;
	}

	schedule.enabled = true;
	schedule.blocks.push(makeBlock(formatMinute(range.start), formatMinute(end), "available"));
};

const cancelBlockDrag = () => {
	dragDraft.value = null;
};

const beginBlockResize = (
	weekIndex: number,
	day: DayKey,
	block: TimeBlock,
	edge: "start" | "end",
	event: PointerEvent,
) => {
	if (event.button !== 0) {
		return;
	}

	event.preventDefault();
	event.stopPropagation();
	(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
	resizingBlock.value = {
		weekIndex,
		day,
		blockId: block.id,
		edge,
		originalStart: parseTime(block.starts_at),
		originalEnd: blockEndMinute(block),
	};
};

const resizeBlock = (event: PointerEvent) => {
	const resize = resizingBlock.value;

	if (!resize) {
		return;
	}

	const schedule = draftWeeks.value[resize.weekIndex]?.[resize.day];
	const block = schedule?.blocks.find(candidate => candidate.id === resize.blockId);

	if (!schedule || !block) {
		return;
	}

	const minute = capResizeMinuteBeforeOverlap(
		schedule,
		block,
		resize.edge,
		minuteFromPointer(event, event.currentTarget as HTMLElement),
	);
	const start = resize.edge === "start"
		? Math.min(minute, resize.originalEnd - 15)
		: resize.originalStart;
	const end = resize.edge === "end"
		? Math.max(minute, resize.originalStart + 15)
		: resize.originalEnd;
	const normalizedStart = Math.max(0, Math.min(1425, start));
	const normalizedEnd = Math.min(1440, Math.max(normalizedStart + 15, end));

	if (overlapsExistingBlock(schedule, normalizedStart, normalizedEnd, block.id)) {
		return;
	}

	block.starts_at = formatMinute(normalizedStart);
	block.ends_at = formatMinute(normalizedEnd);
};

const finishBlockResize = () => {
	resizingBlock.value = null;
};

const cancelTimelineInteraction = () => {
	cancelBlockDrag();
	finishBlockResize();
	hoverTime.value = null;
};

const copyFirstWeek = () => {
	for (let index = 1; index < visibleWeekCount.value; index++) {
		draftWeeks.value[index] = cloneWeeks([draftWeeks.value[0]], true)[0];
	}
};

const applyWeekendLock = () => {
	if (!lockWeekends.value) {
		return;
	}

	draftWeeks.value.forEach((week) => {
		week.saturday.enabled = false;
		week.sunday.enabled = false;
	});
};

const addException = (value: DateValue | undefined) => {
	if (!value) {
		return;
	}

	const date = value.toString();

	if (!draftExceptions.value.some(exception => exception.date === date)) {
		draftExceptions.value.push({
			id: nextExceptionId++,
			date,
			has_time_range: false,
			starts_at: "18:00",
			ends_at: "22:00",
		});
	}

	exceptionDate.value = value;
	exceptionCalendarOpen.value = false;
};

const removeException = (exceptionId: number) => {
	draftExceptions.value = draftExceptions.value.filter(exception => exception.id !== exceptionId);
};

onBeforeUnmount(cancelTimelineInteraction);
</script>

<template>
	<UButton
		color="primary"
		icon="i-lucide-calendar-days"
		:label="t('groups.availability.schedule.button')"
		@click="open"
	/>

	<UModal
		v-model:open="isOpen"
		:title="t('groups.availability.schedule.title')"
		:ui="{
			content: 'max-w-[calc(100vw-1rem)] sm:max-w-7xl',
			body: 'max-h-[calc(100dvh-9rem)] overflow-y-auto p-0 sm:p-0',
		}"
	>
		<template #body>
			<div class="grid min-h-0 xl:grid-cols-[minmax(0,1fr)_17rem]">
				<div class="min-w-0 p-4 sm:p-6">
					<div class="flex flex-col gap-5 border-b border-default pb-5 lg:flex-row lg:items-end lg:justify-between">
						<div class="min-w-0 space-y-4">
							<UTabs
								v-model="cadence"
								:items="cadenceOptions"
								:content="false"
								variant="pill"
								class="w-full sm:w-auto"
							/>
							<div class="flex flex-wrap items-center gap-3 text-sm text-muted">
								<span>{{ repeatable ? t(`groups.availability.schedule.repeat_pattern.${cadence}`) : t('groups.availability.schedule.one_time') }}</span>
								<UPopover v-model:open="startsOnCalendarOpen">
									<UButton
										color="neutral"
										variant="outline"
										icon="i-lucide-calendar"
										size="sm"
										:label="formattedStartDate"
									/>

									<template #content>
										<div class="p-3">
											<UCalendar
												:model-value="startsOnDate"
												:week-starts-on="1"
												:year-controls="false"
												:prevent-deselect="true"
												color="primary"
												class="min-w-72"
												@update:model-value="updateStartsOn"
											/>
										</div>
									</template>
								</UPopover>
							</div>
						</div>

						<div class="flex items-center justify-between gap-4 rounded-lg border border-default bg-elevation-1 px-3 py-2 shadow-xs lg:min-w-48 dark:bg-elevation-3 dark:shadow-none">
							<span class="text-sm font-medium text-toned">{{ t('groups.availability.schedule.repeatable') }}</span>
							<USwitch v-model="repeatable" />
						</div>
					</div>

					<p class="mt-4 text-sm font-medium text-highlighted">{{ t('groups.availability.schedule.editing_timezone', { timezone: scheduleTimeZone }) }}</p>
					<p class="mt-1 text-xs text-muted">{{ t('groups.availability.schedule.preview_hint', { mode: timeDisplayLabel, timezone: displayTimeZoneLabel }) }}</p>

					<div class="mt-5 grid items-start gap-4" :class="visibleWeekCount === 1 ? 'grid-cols-1' : '2xl:grid-cols-2'">
						<section
							v-for="(week, weekIndex) in visibleWeeks"
							:key="weekIndex"
							class="rounded-xl border border-default bg-elevation-1 shadow-xs dark:bg-elevation-2 dark:shadow-none"
						>
							<header class="flex items-center justify-between rounded-t-xl border-b border-default bg-muted px-4 py-3">
								<div class="flex min-w-0 items-center gap-2">
									<span class="size-2 shrink-0 rounded-full" :class="weekIndex % 2 === 0 ? 'bg-primary' : 'bg-primary/60'" />
									<p class="truncate font-semibold text-toned">{{ weekLabel(weekIndex) }}</p>
									<span class="truncate text-xs text-muted">{{ weekRange(weekIndex) }}</span>
								</div>
								<UButton
									v-if="weekIndex > 0"
									color="neutral"
									variant="ghost"
									icon="i-lucide-clipboard-copy"
									size="xs"
									:label="t('groups.availability.schedule.paste')"
									@click="draftWeeks[weekIndex] = cloneWeeks([draftWeeks[0]], true)[0]"
								/>
							</header>

							<div class="grid grid-cols-[6.75rem_minmax(0,1fr)] px-3 pt-3 text-[10px] text-muted">
								<span />
								<div class="flex justify-between px-0.5">
									<span v-for="tick in timeTicks" :key="tick">{{ formatTimeTick(tick) }}</span>
								</div>
							</div>

							<div class="space-y-1 px-3 pt-2 pb-3">
								<div
									v-for="day in dayKeys"
									:key="day"
									class="grid grid-cols-[6.75rem_minmax(0,1fr)] items-center gap-2 py-1"
								>
									<div class="flex min-w-0 items-center gap-2">
										<UIcon
											v-if="lockWeekends && (day === 'saturday' || day === 'sunday')"
											name="i-lucide-lock"
											class="size-4 shrink-0 text-muted"
										/>
										<UCheckbox
											v-else
											v-model="week[day].enabled"
											size="sm"
											@update:model-value="toggleDay(week, day)"
										/>
										<span :title="formatDateOnly(dayDate(weekIndex, dayKeys.indexOf(day) + 1))" class="truncate text-xs font-medium" :class="week[day].enabled ? 'text-toned' : 'text-muted'">
											{{ t(`groups.availability.schedule.days_short.${day}`) }}
										</span>
										<div class="ml-auto flex items-center gap-0.5">
											<UButton
												color="neutral"
												variant="ghost"
												icon="i-lucide-copy"
												size="xs"
												:title="t('groups.availability.schedule.copy_day')"
												:aria-label="t('groups.availability.schedule.copy_day')"
												class="size-5 p-0"
												@click="copyDay(week[day])"
											/>
											<UButton
												color="neutral"
												variant="ghost"
												icon="i-lucide-clipboard"
												size="xs"
												:title="t('groups.availability.schedule.paste_day')"
												:aria-label="t('groups.availability.schedule.paste_day')"
												:disabled="!copiedDaySchedule || isWeekendLocked(day)"
												class="size-5 p-0"
												@click="pasteDay(week, day)"
											/>
										</div>
									</div>

									<div
										class="relative h-7 touch-pan-y rounded-md border border-default bg-elevation-2 dark:bg-elevation-3"
										:class="isWeekendLocked(day) ? 'cursor-not-allowed opacity-60' : 'cursor-crosshair'"
										@pointerdown="beginBlockDrag(weekIndex, day, $event)"
										@pointermove="moveTimelinePointer(weekIndex, day, $event)"
										@pointerup="finishBlockDrag(); finishBlockResize()"
										@pointercancel="cancelTimelineInteraction"
										@pointerleave="clearHoverTime"
									>
										<span
											v-for="tick in timeTicks.slice(1, -1)"
											:key="tick"
											class="absolute inset-y-0 w-px bg-default"
											:style="{ left: `${(tick / 24) * 100}%` }"
										/>

										<span
											v-if="dragDraft?.weekIndex === weekIndex && dragDraft.day === day"
											class="pointer-events-none absolute inset-y-1 z-20 min-w-1 rounded-sm border border-primary/80 bg-primary/30"
											:style="dragPreviewStyle"
										/>

										<span
											v-if="hoverTime?.weekIndex === weekIndex && hoverTime.day === day"
											class="pointer-events-none absolute -top-8 z-30 -translate-x-1/2 rounded-md border border-primary/50 bg-elevation-1 dark:bg-elevation-3 px-2 py-1 text-[10px] font-semibold text-highlighted shadow-lg"
											:style="hoverTimeStyle"
										>
											{{ formatWallTime(formatMinute(hoverTime.minute)) }} · {{ scheduleTimeZone }}
												<span class="block">{{ minutePreview(weekIndex, dayKeys.indexOf(day) + 1, hoverTime.minute) }}</span>
										</span>

										<template v-for="entry in timelineEntries[weekIndex][day]" :key="`${entry.sourceDate}:${entry.block.id}`">
											<UPopover>
												<button
													type="button"
													class="group absolute inset-y-1 z-10 min-w-2 rounded-sm border transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
													:class="blockTone(entry.block.status)"
													:style="blockStyle(entry)"
													:title="`${formatWallTime(entry.block.starts_at)} – ${formatWallTime(entry.block.ends_at)} · ${scheduleTimeZone}\n${windowPreview(entry.sourceDate, entry.block.starts_at, entry.block.ends_at)}`"
													@pointerdown.stop
												>
													<span
														v-if="!entry.continuation && blockEndMinute(entry.block) <= 1440"
														class="absolute inset-y-0 left-0 w-2 cursor-ew-resize border-r border-black/20 bg-black/10 dark:border-white/30 dark:bg-white/10 opacity-0 transition-opacity group-hover:opacity-100"
														@pointerdown="beginBlockResize(entry.sourceWeek, entry.sourceDay, entry.block, 'start', $event)"
													/>
													<span
														v-if="!entry.continuation && blockEndMinute(entry.block) <= 1440"
														class="absolute inset-y-0 right-0 w-2 cursor-ew-resize border-l border-black/20 bg-black/10 dark:border-white/30 dark:bg-white/10 opacity-0 transition-opacity group-hover:opacity-100"
														@pointerdown="beginBlockResize(entry.sourceWeek, entry.sourceDay, entry.block, 'end', $event)"
													/>
												</button>

												<template #content>
													<div class="w-64 space-y-3 p-3">
														<p class="text-xs text-muted">{{ t('groups.availability.schedule.editing_timezone', { timezone: scheduleTimeZone }) }}</p>
														<UFormField :label="t('groups.availability.schedule.block_type')">
															<USelect v-model="entry.block.status" :items="statusOptions" value-key="value" class="w-full" />
														</UFormField>
														<div class="grid grid-cols-2 gap-2">
															<UInput v-model="entry.block.starts_at" type="time" size="sm" />
															<UInput v-model="entry.block.ends_at" type="time" size="sm" />
														</div>
														<p class="text-xs text-primary">{{ windowPreview(entry.sourceDate, entry.block.starts_at, entry.block.ends_at) }}</p>
														<UButton
															color="error"
															variant="ghost"
															icon="i-lucide-trash-2"
															:label="t('groups.availability.schedule.remove_block')"
															class="w-full justify-center"
															@click="removeBlock(draftWeeks[entry.sourceWeek][entry.sourceDay], entry.block.id)"
														/>
													</div>
												</template>
											</UPopover>
										</template>
									</div>
								</div>
							</div>

							<div class="border-t border-default px-3 py-2 text-center">
								<UPopover v-model:open="addBlockPopoverOpen[weekIndex]">
									<UButton
										color="primary"
										variant="link"
										icon="i-lucide-plus"
										size="xs"
										:label="t('groups.availability.schedule.add_block')"
									/>

									<template #content>
										<div class="w-64 space-y-3 p-3 text-left">
											<UFormField :label="t('groups.availability.schedule.select_day')">
												<USelect
													v-model="addBlockDays[weekIndex]"
													:items="dayOptions"
													value-key="value"
													class="w-full"
												/>
											</UFormField>
											<UButton
												color="primary"
												icon="i-lucide-plus"
												:label="t('groups.availability.schedule.add')"
												class="w-full justify-center"
												@click="addBlockToWeek(week, weekIndex)"
											/>
										</div>
									</template>
								</UPopover>
							</div>
						</section>
					</div>

					<div class="mt-5 flex items-center gap-2 rounded-lg border border-primary/20 bg-primary/5 px-3 py-3 text-sm text-muted">
						<UIcon name="i-lucide-info" class="size-4 shrink-0 text-primary" />
						<span>{{ t('groups.availability.schedule.visibility_hint') }}</span>
					</div>

					<section v-if="futureExceptions.length > 0" class="mt-5 rounded-xl border border-default bg-elevation-1 shadow-xs dark:bg-elevation-2 dark:shadow-none">
						<header class="flex items-center gap-2 rounded-t-xl border-b border-default bg-muted px-4 py-3">
							<UIcon name="i-lucide-calendar-x" class="size-4 text-primary" />
							<p class="font-semibold text-toned">{{ t('groups.availability.schedule.exceptions_title') }}</p>
						</header>
						<div class="divide-y divide-default">
							<div
								v-for="exception in futureExceptions"
								:key="exception.id"
								class="flex flex-col gap-3 px-4 py-3 lg:flex-row lg:items-center"
							>
								<div class="min-w-0 flex-1">
									<p class="font-medium text-toned">{{ formatExceptionDate(exception.date) }}</p>
									<p class="text-xs text-muted">{{ t('groups.availability.schedule.legend.unavailable') }}</p>
									<p class="mt-1 text-xs text-primary">{{ windowPreview(exception.date, exception.has_time_range ? exception.starts_at : '00:00', exception.has_time_range ? exception.ends_at : '00:00') }}</p>
								</div>
								<div class="flex flex-wrap items-center gap-3">
									<div class="flex items-center gap-2">
										<span class="text-xs text-muted">{{ t('groups.availability.schedule.specific_hours') }}</span>
										<USwitch v-model="exception.has_time_range" size="sm" />
									</div>
									<div v-if="exception.has_time_range" class="flex items-center gap-2">
										<UInput v-model="exception.starts_at" type="time" size="sm" />
										<span class="text-xs text-muted">–</span>
										<UInput v-model="exception.ends_at" type="time" size="sm" />
									</div>
									<UButton
										color="error"
										variant="ghost"
										icon="i-lucide-trash-2"
										size="xs"
										:title="t('groups.availability.schedule.remove_exception')"
										@click="removeException(exception.id)"
									/>
								</div>
							</div>
						</div>
					</section>

					<div class="mt-5 flex justify-between gap-3">
						<UButton color="neutral" variant="ghost" :label="t('general.cancel')" @click="isOpen = false" />
						<UButton
							color="primary"
							icon="i-lucide-check"
							:label="t('groups.availability.schedule.save')"
							:loading="isSaving"
							@click="save"
						/>
					</div>
				</div>

				<aside class="border-t border-default bg-elevation-2 dark:bg-elevation-3 xl:border-t-0 xl:border-l">
					<section class="space-y-4 border-b border-default p-5">
						<p class="text-sm font-semibold text-toned">{{ t('groups.availability.schedule.summary_title') }}</p>
						<p class="text-xs text-muted">{{ t('groups.availability.schedule.editing_timezone', { timezone: scheduleTimeZone }) }}<br>{{ timeDisplayLabel }}: {{ displayTimeZoneLabel }}</p>
						<div class="flex gap-3 text-sm text-muted">
							<UIcon name="i-lucide-calendar-range" class="mt-0.5 size-4 shrink-0 text-toned" />
							<p>
								{{ repeatable ? t(`groups.availability.schedule.repeat_pattern.${cadence}`) : t('groups.availability.schedule.one_time') }}
								<br><span class="text-toned">{{ formattedStartDate }}</span>
							</p>
						</div>
						<div v-for="(_, weekIndex) in visibleWeeks" :key="weekIndex" class="flex gap-3 text-sm">
							<span class="mt-1 size-2.5 shrink-0 rounded-full" :class="weekIndex % 2 === 0 ? 'bg-primary' : 'bg-primary/60'" />
							<div>
								<p class="font-medium text-toned">{{ weekLabel(weekIndex) }}</p>
								<p class="text-xs text-muted">{{ weekRange(weekIndex) }}</p>
							</div>
						</div>
					</section>

					<section class="space-y-4 border-b border-default p-5">
						<p class="text-sm font-semibold text-toned">{{ t('groups.availability.schedule.controls_title') }}</p>
						<button
							v-if="visibleWeekCount > 1"
							type="button"
							class="-mx-2 flex w-[calc(100%+1rem)] items-start gap-3 rounded-lg p-2 text-left transition-colors hover:bg-primary/8 focus-visible:outline-2 focus-visible:outline-primary"
							@click="copyFirstWeek"
						>
							<UIcon name="i-lucide-copy" class="mt-0.5 size-4 shrink-0 text-toned" />
							<span>
								<span class="block text-sm font-medium text-toned">{{ t('groups.availability.schedule.copy_weeks') }}</span>
								<span class="block text-xs text-muted">{{ t('groups.availability.schedule.copy_weeks_hint') }}</span>
							</span>
						</button>
						<div class="flex items-start justify-between gap-3">
							<div class="flex gap-3">
								<UIcon name="i-lucide-pause-circle" class="mt-0.5 size-4 shrink-0 text-toned" />
								<span>
									<span class="block text-sm font-medium text-toned">{{ t('groups.availability.schedule.on_hiatus') }}</span>
									<span class="block text-xs text-muted">{{ t('groups.availability.schedule.on_hiatus_hint') }}</span>
								</span>
							</div>
							<USwitch v-model="onHiatus" size="sm" />
						</div>
						<div class="flex items-start justify-between gap-3">
							<div class="flex gap-3">
								<UIcon name="i-lucide-calendar-off" class="mt-0.5 size-4 shrink-0 text-toned" />
								<span>
									<span class="block text-sm font-medium text-toned">{{ t('groups.availability.schedule.lock_weekends') }}</span>
									<span class="block text-xs text-muted">{{ t('groups.availability.schedule.lock_weekends_hint') }}</span>
								</span>
							</div>
							<USwitch v-model="lockWeekends" size="sm" @update:model-value="applyWeekendLock" />
						</div>
						<div class="space-y-2">
							<div class="flex gap-3">
								<UIcon name="i-lucide-globe-2" class="mt-0.5 size-4 shrink-0 text-toned" />
								<span>
									<span class="block text-sm font-medium text-toned">{{ t('groups.availability.schedule.timezone') }}</span>
									<span class="block text-xs text-muted">{{ t('groups.availability.schedule.timezone_hint') }}</span>
								</span>
							</div>
							<USelectMenu
								v-model="scheduleTimeZone"
								:items="timeZoneOptions"
								value-key="value"
								:search-input="{ placeholder: t('groups.availability.schedule.timezone_search') }"
								class="w-full"
							/>
						</div>
						<UPopover
							v-model:open="exceptionCalendarOpen"
							:content="{ side: 'left', align: 'start', collisionPadding: 8 }"
						>
							<button
								type="button"
								class="group -mx-2 flex w-[calc(100%+1rem)] items-start gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-primary/8 focus-visible:outline-2 focus-visible:outline-primary"
							>
								<UIcon name="i-lucide-calendar-plus" class="mt-0.5 size-4 shrink-0 text-toned transition-colors group-hover:text-primary" />
								<span class="min-w-0 flex-1">
									<span class="block text-sm font-medium text-toned transition-colors group-hover:text-highlighted">{{ t('groups.availability.schedule.add_exception') }}</span>
									<span class="block text-xs text-muted">{{ t('groups.availability.schedule.add_exception_hint') }}</span>
								</span>
								<UIcon name="i-lucide-calendar" class="mt-0.5 size-4 shrink-0 text-muted transition-colors group-hover:text-primary" />
							</button>

							<template #content>
								<div class="p-3">
									<UCalendar
										:model-value="exceptionDate"
										:min-value="minimumExceptionDate"
										:week-starts-on="1"
										:year-controls="false"
										:prevent-deselect="true"
										color="primary"
										class="min-w-72"
										@update:model-value="addException"
									/>
								</div>
							</template>
						</UPopover>
					</section>

					<section class="space-y-3 p-5">
						<p class="text-sm font-semibold text-toned">{{ t('groups.availability.schedule.legend_title') }}</p>
						<div
							v-for="status in [...statusKeys, 'unavailable']"
							:key="status"
							class="flex items-center gap-3 text-sm text-muted"
						>
							<span class="size-3.5 rounded-sm border" :class="legendTone(status as AvailabilityStatus | 'unavailable')" />
							<span>{{ t(`groups.availability.schedule.legend.${status}`) }}</span>
						</div>
					</section>
				</aside>
			</div>
		</template>
	</UModal>
</template>
