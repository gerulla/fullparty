<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";
import type { GroupAvailabilityOverviewPayload } from "@/Types/Groups";

const props = defineProps<{
	overview: GroupAvailabilityOverviewPayload
	selectedStartsAt: string
	selectedEndsAt: string
}>();
const emit = defineEmits<{
	'update:selectedRange': [range: { starts_at: string, ends_at: string }]
}>();

const { locale, t } = useI18n();
const { withDisplayTimeZone } = useTimeDisplayMode();
const selectionAnchor = ref(0);
const selectionEnd = ref(0);
const selecting = ref(false);
const isCompactViewport = ref(false);
let tabletMediaQuery: MediaQueryList | null = null;

const syncSelectionFromProps = () => {
	const overviewStart = new Date(props.overview.starts_at).getTime();
	const bucketCount = props.overview.buckets.length;
	const startOffset = Math.floor((new Date(props.selectedStartsAt).getTime() - overviewStart) / 3_600_000);
	const endOffset = Math.ceil((new Date(props.selectedEndsAt).getTime() - overviewStart) / 3_600_000) - 1;

	selectionAnchor.value = Math.max(0, Math.min(bucketCount - 1, startOffset));
	selectionEnd.value = Math.max(selectionAnchor.value, Math.min(bucketCount - 1, endOffset));
};

syncSelectionFromProps();
watch(() => [props.selectedStartsAt, props.selectedEndsAt], syncSelectionFromProps);

const dateKey = (date: Date) => {
	const parts = new Intl.DateTimeFormat("en", withDisplayTimeZone({
		year: "numeric",
		month: "2-digit",
		day: "2-digit",
	})).formatToParts(date);
	const part = (type: "year" | "month" | "day") => parts.find(candidate => candidate.type === type)?.value ?? "";

	return `${part("year")}-${part("month")}-${part("day")}`;
};

const daySegments = computed(() => {
	const segments: Array<{
		key: string
		date: Date
		start: number
		length: number
	}> = [];

	props.overview.buckets.forEach((bucket, index) => {
		const date = new Date(bucket.starts_at);
		const key = dateKey(date);
		const current = segments.at(-1);

		if (current?.key === key) {
			current.length++;
		} else {
			segments.push({ key, date, start: index, length: 1 });
		}
	});

	return segments;
});

const timeMarkers = computed(() => props.overview.buckets
	.map((bucket, index) => ({ bucket, index }))
	.filter(({ index }) => index % 6 === 0));

const maximumAvailable = computed(() => Math.max(
	1,
	...props.overview.buckets.map(bucket => bucket.available_count + bucket.tentative_count),
));
const firstSelectedIndex = computed(() => Math.min(selectionAnchor.value, selectionEnd.value));
const lastSelectedIndex = computed(() => Math.max(selectionAnchor.value, selectionEnd.value));
const selectedBuckets = computed(() => props.overview.buckets.slice(
	firstSelectedIndex.value,
	lastSelectedIndex.value + 1,
));
const selectedPeak = computed(() => Math.max(
	0,
	...selectedBuckets.value.map(bucket => bucket.available_count + bucket.tentative_count),
));
const selectedRangeLabel = computed(() => {
	const first = selectedBuckets.value[0];
	const last = selectedBuckets.value.at(-1);

	if (!first || !last) return "";

	const startsAt = new Date(first.starts_at);
	const endsAt = new Date(new Date(last.starts_at).getTime() + 60 * 60 * 1000);
	const dateFormatter = new Intl.DateTimeFormat(locale.value, withDisplayTimeZone({ weekday: "short", day: "numeric", month: "short" }));
	const timeFormatter = new Intl.DateTimeFormat(locale.value, withDisplayTimeZone({ hour: "2-digit", minute: "2-digit" }));

	if (dateKey(startsAt) === dateKey(endsAt)) {
		return `${dateFormatter.format(startsAt)} · ${timeFormatter.format(startsAt)} – ${timeFormatter.format(endsAt)}`;
	}

	return `${dateFormatter.format(startsAt)} ${timeFormatter.format(startsAt)} – ${dateFormatter.format(endsAt)} ${timeFormatter.format(endsAt)}`;
});

const dayLabel = (date: Date) => new Intl.DateTimeFormat(locale.value, withDisplayTimeZone({
	weekday: "short",
	day: "numeric",
	month: "short",
})).format(date);

const timeLabel = (date: Date) => new Intl.DateTimeFormat(locale.value, withDisplayTimeZone({
	hour: "numeric",
})).format(date);

const bucketTotal = (bucket: GroupAvailabilityOverviewPayload["buckets"][number]) => (
	bucket.available_count + bucket.tentative_count
);

const barStyle = (bucket: GroupAvailabilityOverviewPayload["buckets"][number]) => {
	const ratio = bucketTotal(bucket) / maximumAvailable.value;

	return {
		height: `${Math.max(4, Math.round(ratio * 52))}px`,
		opacity: String(0.22 + (ratio * 0.78)),
	};
};

const selectionStyle = computed(() => ({
	left: `${(firstSelectedIndex.value / props.overview.buckets.length) * 100}%`,
	width: `${((lastSelectedIndex.value - firstSelectedIndex.value + 1) / props.overview.buckets.length) * 100}%`,
}));

const daySegmentStyle = (start: number, length: number) => ({
	gridColumn: `${start + 1} / span ${length}`,
});

const boundaryStyle = (start: number) => ({
	left: `${(start / props.overview.buckets.length) * 100}%`,
});

const beginSelection = (index: number, event: PointerEvent) => {
	if (isCompactViewport.value) return;

	event.preventDefault();
	selectionAnchor.value = index;
	selectionEnd.value = index;
	selecting.value = true;
};

const extendSelection = (event: PointerEvent) => {
	if (!selecting.value) return;

	const target = document.elementFromPoint(event.clientX, event.clientY)
		?.closest<HTMLElement>("[data-availability-index]");
	const index = Number(target?.dataset.availabilityIndex);

	if (Number.isInteger(index)) selectionEnd.value = index;
};

const finishSelection = () => {
	if (selecting.value) {
		const first = props.overview.buckets[firstSelectedIndex.value];
		const last = props.overview.buckets[lastSelectedIndex.value];

		if (first && last) {
			emit('update:selectedRange', {
				starts_at: first.starts_at,
				ends_at: new Date(new Date(last.starts_at).getTime() + 3_600_000).toISOString(),
			});
		}
	}

	selecting.value = false;
};

const syncSelectionMode = () => {
	isCompactViewport.value = tabletMediaQuery?.matches ?? false;
	selecting.value = false;
};

onMounted(() => {
	tabletMediaQuery = window.matchMedia("(max-width: 1023px)");
	syncSelectionMode();
	tabletMediaQuery.addEventListener("change", syncSelectionMode);
	window.addEventListener("pointerup", finishSelection);
});

onBeforeUnmount(() => {
	tabletMediaQuery?.removeEventListener("change", syncSelectionMode);
	window.removeEventListener("pointerup", finishSelection);
});
</script>

<template>
	<UCard :ui="{ body: 'p-0 sm:p-0' }">
		<div class="flex flex-wrap items-start justify-between gap-3 border-b border-default px-4 py-3">
			<div>
				<p class="text-sm font-semibold text-highlighted">
					{{ t('groups.availability.overview.title') }}
				</p>
				<p class="mt-0.5 text-xs text-muted">
					{{ t('groups.availability.overview.subtitle', { count: overview.member_count }) }}
				</p>
			</div>

			<div class="flex items-center gap-2 text-xs">
				<span class="size-2 bg-brand-500" />
				<span class="font-medium text-highlighted">{{ selectedRangeLabel }}</span>
				<UBadge color="primary" variant="soft" size="md">
					{{ t('groups.availability.overview.peak', { count: selectedPeak }) }}
				</UBadge>
			</div>
		</div>

		<div class="overflow-x-auto px-4 py-3">
			<div
				class="min-w-[1050px] select-none"
				@pointermove="extendSelection"
			>
				<div
					class="grid border-b border-default"
					:style="{ gridTemplateColumns: `repeat(${overview.buckets.length}, minmax(0, 1fr))` }"
				>
					<div
						v-for="segment in daySegments"
						:key="segment.key"
						class="border-l border-default px-1 pb-2 text-center first:border-l-0"
						:style="daySegmentStyle(segment.start, segment.length)"
					>
						<p class="truncate text-xs font-semibold text-highlighted">
							{{ dayLabel(segment.date) }}
						</p>
					</div>
				</div>

				<div class="relative h-5 text-[9px] text-dimmed">
					<span
						v-for="marker in timeMarkers"
						:key="marker.index"
						class="absolute top-1 -translate-x-1/2 whitespace-nowrap"
						:style="boundaryStyle(marker.index + 0.5)"
					>
						{{ timeLabel(new Date(marker.bucket.starts_at)) }}
					</span>
				</div>

				<div class="relative h-16 border-b border-default">
					<div
						class="grid h-full items-end"
						:style="{ gridTemplateColumns: `repeat(${overview.buckets.length}, minmax(0, 1fr))` }"
					>
						<UTooltip
							v-for="(bucket, index) in overview.buckets"
							:key="bucket.starts_at"
								:text="t('groups.availability.overview.bucket', {
									count: bucketTotal(bucket),
									available: bucket.available_count,
									tentative: bucket.tentative_count,
								})"
							>
								<button
									type="button"
									class="relative flex h-full min-w-0 items-end justify-center hover:bg-muted/15"
									:data-availability-index="index"
									:aria-label="t('groups.availability.overview.bucket', {
										count: bucketTotal(bucket),
										available: bucket.available_count,
										tentative: bucket.tentative_count,
									})"
									@pointerdown="beginSelection(index, $event)"
								>
									<span class="w-full bg-brand-500" :style="barStyle(bucket)" />
								</button>
							</UTooltip>
						</div>

						<div
							v-for="segment in daySegments.slice(1)"
							:key="`boundary-${segment.key}`"
							class="pointer-events-none absolute inset-y-0 z-10 border-l border-default"
							:style="boundaryStyle(segment.start)"
						/>

						<div
							class="pointer-events-none absolute inset-y-0 z-20 border-2 border-brand-400 bg-brand-500/8"
							:style="selectionStyle"
						/>
					</div>
			</div>
		</div>

		<div class="flex flex-wrap items-center justify-center gap-x-5 gap-y-2 border-t border-default px-4 py-2.5 text-xs text-muted">
			<span>{{ t('groups.availability.overview.fewer') }}</span>
			<span class="h-2 w-24 bg-gradient-to-r from-brand-500/15 to-brand-500" />
			<span>{{ t('groups.availability.overview.more') }}</span>
			<span class="flex items-center gap-1.5">
				<span class="size-2 bg-brand-500" />
				{{ t('groups.availability.overview.selected') }}
			</span>
		</div>
	</UCard>
</template>
