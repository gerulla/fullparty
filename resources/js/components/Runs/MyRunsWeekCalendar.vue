<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { parseDate, startOfWeek, type DateValue } from "@internationalized/date";
import { CalendarRoot, CalendarGrid, CalendarGridHead, CalendarGridBody, CalendarGridRow, CalendarHeadCell, CalendarCell, CalendarCellTrigger } from "reka-ui";
import { useI18n } from "vue-i18n";

const props = defineProps<{ date: string; minDate: string; runDates: string[] }>();
const emit = defineEmits<{ dateSelected: [date: string] }>();
const { t, locale } = useI18n();
const selectedDate = computed(() => parseDate(props.date));
const minimumDate = computed(() => parseDate(props.minDate));
const placeholder = ref<DateValue>(parseDate(props.date));
const weekStart = computed(() => startOfWeek(placeholder.value, locale.value, 1));
const firstWeek = computed(() => startOfWeek(parseDate(props.minDate), locale.value, 1));
const heading = computed(() => new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(placeholder.value.toDate('UTC')));
watch(() => props.date, (date) => { placeholder.value = parseDate(date); });

const isVisibleWeek = (days: DateValue[]) => days.some((day) => day.compare(placeholder.value) === 0);
const moveWeek = (direction: number) => {
	const next = placeholder.value.add({ weeks: direction });
	placeholder.value = next.compare(parseDate(props.minDate)) < 0 ? parseDate(props.minDate) : next;
};

// The underlying calendar pages by month; keep arrow-key focus within the weekly view.
const navigateDay = async (event: KeyboardEvent) => {
	const offsets: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
	const offset = offsets[event.key];
	const target = event.target as HTMLElement;
	const day = target.closest<HTMLElement>('[data-reka-calendar-cell-trigger]')?.dataset.value;
	if (offset === undefined || !day) return;
	event.preventDefault();
	event.stopPropagation();
	const next = parseDate(day).add({ days: offset });
	if (next.compare(minimumDate.value) < 0) return;
	const calendar = event.currentTarget as HTMLElement;
	placeholder.value = next;
	await nextTick();
	calendar.querySelector<HTMLElement>(`[data-value="${next.toString()}"]`)?.focus();
};
</script>

<template>
	<CalendarRoot
		v-slot="{ grid, weekDays }"
		v-model:placeholder="placeholder"
		:model-value="selectedDate"
		:min-value="minimumDate"
		:locale="locale"
		:week-starts-on="1"
		weekday-format="short"
		prevent-deselect
		class="w-full"
		@keydown.capture="navigateDay"
		@update:model-value="(value) => value && !Array.isArray(value) && emit('dateSelected', value.toString())"
	>
		<div class="mb-2 flex h-8 items-center justify-between gap-2">
			<UButton icon="i-lucide-chevron-left" color="neutral" variant="ghost" size="sm" :disabled="weekStart.compare(firstWeek) <= 0" :aria-label="t('my_runs.tools.previous_week')" @click="moveWeek(-1)" />
			<span class="text-sm font-medium text-toned" aria-live="polite">{{ heading }}</span>
			<UButton icon="i-lucide-chevron-right" color="neutral" variant="ghost" size="sm" :aria-label="t('my_runs.tools.next_week')" @click="moveWeek(1)" />
		</div>
		<CalendarGrid v-for="month in grid" :key="month.value.toString()" class="w-full table-fixed border-collapse select-none">
			<CalendarGridHead>
				<CalendarGridRow>
					<CalendarHeadCell v-for="day in weekDays" :key="day" class="pb-1 text-center text-xs font-normal text-muted">{{ day }}</CalendarHeadCell>
				</CalendarGridRow>
			</CalendarGridHead>
			<CalendarGridBody>
				<CalendarGridRow v-for="week in month.rows.filter(isVisibleWeek)" :key="week[0].toString()">
					<CalendarCell v-for="day in week" :key="day.toString()" :date="day" class="p-0 text-center">
						<CalendarCellTrigger :day="day" :month="month.value"
							class="relative mx-auto flex size-9 items-center justify-center rounded-none text-sm text-toned outline-none hover:bg-elevated focus-visible:ring-2 focus-visible:ring-primary data-[disabled]:pointer-events-none data-[disabled]:opacity-40 data-[selected]:bg-primary data-[selected]:text-inverted"
							@click="day.toString() === date && emit('dateSelected', date)"
						>
							{{ day.day }}
							<span v-if="runDates.includes(day.toString())" class="absolute bottom-0.5 size-1 rounded-full bg-warning" aria-hidden="true" />
						</CalendarCellTrigger>
					</CalendarCell>
				</CalendarGridRow>
			</CalendarGridBody>
		</CalendarGrid>
	</CalendarRoot>
</template>
