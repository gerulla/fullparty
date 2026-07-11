<script setup lang="ts">
import axios from "axios";
import { parseDate } from "@internationalized/date";
import type { DateValue } from "@internationalized/date";
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";
import type {
	GroupAvailabilitySelectionPayload,
	GroupAvailabilitySelectionStatus,
} from "@/Types/Groups";

const props = defineProps<{
	groupSlug: string
	startsAt: string
	endsAt: string
}>();
const emit = defineEmits<{
	'update:range': [range: { starts_at: string, ends_at: string }]
}>();

const { locale, t } = useI18n();
const { displayTimeZone, withDisplayTimeZone } = useTimeDisplayMode();
const details = ref<GroupAvailabilitySelectionPayload | null>(null);
const loading = ref(true);
const failed = ref(false);
const draftStartDate = ref<DateValue>();
const draftEndDate = ref<DateValue>();
const draftStartTime = ref("");
const draftEndTime = ref("");
const startCalendarOpen = ref(false);
const endCalendarOpen = ref(false);
let requestTimer: ReturnType<typeof setTimeout> | null = null;
let requestSequence = 0;

const toLocalInput = (iso: string) => {
	const date = new Date(iso);

	if (displayTimeZone.value === "UTC") return date.toISOString().slice(0, 16);

	const localDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60_000));
	return localDate.toISOString().slice(0, 16);
};

const syncDraftRange = () => {
	const [startDate, startTime] = toLocalInput(props.startsAt).split("T");
	const [endDate, endTime] = toLocalInput(props.endsAt).split("T");
	draftStartDate.value = parseDate(startDate);
	draftEndDate.value = parseDate(endDate);
	draftStartTime.value = startTime;
	draftEndTime.value = endTime;
};

const formatDateTime = (iso: string) => new Intl.DateTimeFormat(locale.value, withDisplayTimeZone({
	weekday: "short",
	day: "numeric",
	month: "short",
	hour: "2-digit",
	minute: "2-digit",
})).format(new Date(iso));

const formatTime = (iso: string) => new Intl.DateTimeFormat(locale.value, withDisplayTimeZone({
	hour: "2-digit",
	minute: "2-digit",
})).format(new Date(iso));

const formatCalendarDate = (date: DateValue | undefined) => date
	? new Intl.DateTimeFormat(locale.value, {
		weekday: "short",
		day: "numeric",
		month: "short",
	}).format(new Date(`${date.toString()}T00:00:00`))
	: "";

const durationMinutes = computed(() => Math.max(
	0,
	Math.round((new Date(props.endsAt).getTime() - new Date(props.startsAt).getTime()) / 60_000),
));
const durationLabel = computed(() => {
	const hours = Math.floor(durationMinutes.value / 60);
	const minutes = durationMinutes.value % 60;

	if (hours && minutes) return t("groups.availability.selection.duration_hours_minutes", { hours, minutes });
	if (hours) return t("groups.availability.selection.duration_hours", { count: hours });
	return t("groups.availability.selection.duration_minutes", { count: minutes });
});
const selectedRangeLabel = computed(() => `${formatDateTime(props.startsAt)} – ${formatDateTime(props.endsAt)}`);
const attendeeCount = computed(() => (
	(details.value?.available_count ?? 0) + (details.value?.tentative_count ?? 0)
));
const availablePercent = computed(() => details.value?.total_members
	? Math.round((attendeeCount.value / details.value.total_members) * 100)
	: 0);
const timelineMarkers = computed(() => {
	const slots = details.value?.slots ?? [];
	const step = Math.max(1, Math.ceil(slots.length / 6));

	return slots
		.map((slot, index) => ({ slot, index }))
		.filter(({ index }) => index % step === 0);
});

const initials = (name: string) => name
	.split(/\s+/)
	.filter(Boolean)
	.slice(0, 2)
	.map(part => part[0])
	.join("")
	.toUpperCase();

const statusClass = (status: GroupAvailabilitySelectionStatus | null) => ({
	available: "bg-success/55",
	tentative: "bg-brand-500/55",
	unavailable: "bg-muted/15",
})[status ?? "unavailable"];

const distributionStyle = (count: number) => ({
	width: `${details.value?.total_members ? (count / details.value.total_members) * 100 : 0}%`,
});

const applyDraftRange = () => {
	if (!draftStartDate.value || !draftEndDate.value || !draftStartTime.value || !draftEndTime.value) return;

	const timezoneSuffix = displayTimeZone.value === "UTC" ? "Z" : "";
	const startsAt = new Date(`${draftStartDate.value.toString()}T${draftStartTime.value}:00${timezoneSuffix}`);
	const endsAt = new Date(`${draftEndDate.value.toString()}T${draftEndTime.value}:00${timezoneSuffix}`);

	if (endsAt <= startsAt) return;

	emit("update:range", {
		starts_at: startsAt.toISOString(),
		ends_at: endsAt.toISOString(),
	});
};

const updateStartDate = (value: DateValue | undefined) => {
	if (!value) return;

	draftStartDate.value = value;
	startCalendarOpen.value = false;
	applyDraftRange();
};

const updateEndDate = (value: DateValue | undefined) => {
	if (!value) return;

	draftEndDate.value = value;
	endCalendarOpen.value = false;
	applyDraftRange();
};

const fetchDetails = async () => {
	const sequence = ++requestSequence;
	loading.value = true;
	failed.value = false;

	try {
		const response = await axios.get(route("groups.dashboard.availability.selection", props.groupSlug), {
			params: {
				starts_at: props.startsAt,
				ends_at: props.endsAt,
			},
		});

		if (sequence === requestSequence) details.value = response.data.data;
	} catch {
		if (sequence === requestSequence) failed.value = true;
	} finally {
		if (sequence === requestSequence) loading.value = false;
	}
};

const queueFetch = () => {
	if (requestTimer) clearTimeout(requestTimer);
	requestTimer = setTimeout(fetchDetails, 250);
};

watch(() => [props.startsAt, props.endsAt, displayTimeZone.value], () => {
	syncDraftRange();
	queueFetch();
}, { immediate: true });

onBeforeUnmount(() => {
	if (requestTimer) clearTimeout(requestTimer);
	requestSequence++;
});
</script>

<template>
	<div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(22rem,0.85fr)]">
		<UCard :ui="{ body: 'p-0 sm:p-0' }">
			<div class="flex flex-col gap-4 border-b border-default px-4 py-3 lg:flex-row lg:items-end lg:justify-between">
				<div class="min-w-0">
					<p class="text-xs font-semibold uppercase text-muted">
						{{ t('groups.availability.selection.title') }}
					</p>
					<div class="mt-1 flex flex-wrap items-center gap-2">
						<p class="font-semibold text-highlighted">{{ selectedRangeLabel }}</p>
						<UBadge color="primary" variant="soft">{{ durationLabel }}</UBadge>
					</div>
				</div>

				<div class="grid shrink-0 grid-cols-1 gap-2 sm:grid-cols-2">
					<UFormField :label="t('groups.availability.selection.starts_at')">
						<div class="flex gap-1.5">
							<UPopover v-model:open="startCalendarOpen">
								<UButton
									color="neutral"
									variant="outline"
									icon="i-lucide-calendar"
									:label="formatCalendarDate(draftStartDate)"
								/>

								<template #content>
									<div class="border border-default bg-neutral-950 p-3">
										<UCalendar
											:model-value="draftStartDate"
											:week-starts-on="1"
											:year-controls="false"
											:prevent-deselect="true"
											color="primary"
											class="min-w-72"
											@update:model-value="updateStartDate"
										/>
									</div>
								</template>
							</UPopover>
							<UInput v-model="draftStartTime" type="time" step="1800" class="w-28" @change="applyDraftRange" />
						</div>
					</UFormField>
					<UFormField :label="t('groups.availability.selection.ends_at')">
						<div class="flex gap-1.5">
							<UPopover v-model:open="endCalendarOpen">
								<UButton
									color="neutral"
									variant="outline"
									icon="i-lucide-calendar"
									:label="formatCalendarDate(draftEndDate)"
								/>

								<template #content>
									<div class="border border-default bg-neutral-950 p-3">
										<UCalendar
											:model-value="draftEndDate"
											:week-starts-on="1"
											:year-controls="false"
											:prevent-deselect="true"
											color="primary"
											class="min-w-72"
											@update:model-value="updateEndDate"
										/>
									</div>
								</template>
							</UPopover>
							<UInput v-model="draftEndTime" type="time" step="1800" class="w-28" @change="applyDraftRange" />
						</div>
					</UFormField>
				</div>
			</div>

			<div v-if="loading" class="space-y-3 p-4">
				<USkeleton class="h-5 w-36" />
				<div v-for="index in 6" :key="index" class="flex items-center gap-3">
					<USkeleton class="size-9 shrink-0 rounded-full" />
					<USkeleton class="h-9 w-32 shrink-0" />
					<USkeleton class="h-8 flex-1" />
				</div>
			</div>

			<div v-else-if="failed" class="p-8 text-center text-sm text-error">
				{{ t('groups.availability.selection.load_error') }}
			</div>

			<div v-else-if="details" class="p-4">
				<div class="mb-2 flex items-center justify-between gap-3">
					<p class="text-sm font-semibold text-highlighted">
						{{ t('groups.availability.selection.people', { count: details.members.length }) }}
					</p>
					<p class="text-xs text-muted">{{ durationLabel }}</p>
				</div>

				<div v-if="details.members.length" class="max-h-[34rem] overflow-auto border border-default">
					<div class="sticky top-0 z-10 ml-44 h-7 border-b border-default bg-default sm:ml-52">
						<span
							v-for="marker in timelineMarkers"
							:key="marker.index"
							class="absolute top-1 -translate-x-1/2 text-[10px] text-muted"
							:style="{ left: `${((marker.index + 0.5) / details.slots.length) * 100}%` }"
						>
							{{ formatTime(marker.slot.starts_at) }}
						</span>
					</div>

					<div
						v-for="member in details.members"
						:key="member.id"
						class="flex min-h-12 border-b border-default last:border-b-0"
					>
						<div class="flex w-44 shrink-0 items-center gap-2 px-2 sm:w-52">
							<UAvatar :src="member.avatar_url ?? undefined" :alt="member.name" :text="initials(member.name)" size="sm" />
							<div class="min-w-0">
								<p class="truncate text-xs font-semibold text-highlighted">{{ member.name }}</p>
								<p class="text-[10px] text-muted">
									{{ t(`groups.availability.selection.status.${member.status}`) }}
								</p>
							</div>
						</div>

						<div
							class="grid flex-1"
							:style="{ gridTemplateColumns: `repeat(${member.slots.length}, minmax(5px, 1fr))` }"
						>
							<span
								v-for="(status, index) in member.slots"
								:key="index"
								class="border-l border-default/50 first:border-l-0"
								:class="statusClass(status)"
							/>
						</div>
					</div>
				</div>

				<div v-else class="border border-default px-4 py-10 text-center text-sm text-muted">
					{{ t('groups.availability.selection.no_members') }}
				</div>

				<div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-muted">
					<span class="flex items-center gap-1.5"><span class="size-2.5 bg-success/55" />{{ t('groups.availability.schedule.legend.available') }}</span>
					<span class="flex items-center gap-1.5"><span class="size-2.5 bg-brand-500/55" />{{ t('groups.availability.schedule.legend.tentative') }}</span>
					<span class="flex items-center gap-1.5"><span class="size-2.5 border border-default bg-muted/15" />{{ t('groups.availability.schedule.legend.unavailable') }}</span>
				</div>
			</div>
		</UCard>

		<UCard :ui="{ body: 'p-0 sm:p-0' }">
			<div class="border-b border-default px-4 py-3">
				<p class="text-sm font-semibold text-highlighted">
					{{ t('groups.availability.selection.summary_title') }}
				</p>
				<p class="mt-0.5 text-xs text-muted">{{ selectedRangeLabel }} · {{ durationLabel }}</p>
			</div>

			<div v-if="loading" class="space-y-4 p-4">
				<div class="grid grid-cols-3 gap-3">
					<USkeleton v-for="index in 3" :key="index" class="h-20" />
				</div>
				<USkeleton class="h-16" />
				<USkeleton class="h-40" />
				<USkeleton class="h-16" />
			</div>

			<div v-else-if="failed" class="p-8 text-center text-sm text-error">
				{{ t('groups.availability.selection.load_error') }}
			</div>

			<div v-else-if="details" class="divide-y divide-default">
				<div class="grid grid-cols-3 divide-x divide-default">
					<div class="p-3">
						<p class="text-[10px] uppercase text-muted">{{ t('groups.availability.selection.total_available') }}</p>
						<p class="mt-1 text-lg font-semibold text-brand-300">{{ attendeeCount }} / {{ details.total_members }}</p>
						<p class="text-[10px] text-muted">{{ availablePercent }}%</p>
					</div>
					<div class="p-3">
						<p class="text-[10px] uppercase text-muted">{{ t('groups.availability.selection.highest_overlap') }}</p>
						<p class="mt-1 text-lg font-semibold text-brand-300">{{ details.highest_overlap }}</p>
						<p class="text-[10px] text-muted">{{ t('groups.availability.selection.people_short') }}</p>
					</div>
					<div class="p-3">
						<p class="text-[10px] uppercase text-muted">{{ t('groups.availability.selection.best_time') }}</p>
						<p class="mt-1 text-sm font-semibold text-highlighted">
							{{ details.best_time ? `${formatTime(details.best_time.starts_at)} – ${formatTime(details.best_time.ends_at)}` : '—' }}
						</p>
						<p class="text-[10px] text-muted">{{ t('groups.availability.selection.best_overlap') }}</p>
					</div>
				</div>

				<div class="p-4">
					<p class="mb-3 text-xs font-semibold text-highlighted">{{ t('groups.availability.selection.distribution') }}</p>
					<div class="flex h-3 overflow-hidden bg-muted/10">
						<span class="bg-success/65" :style="distributionStyle(details.available_count)" />
						<span class="bg-brand-500/65" :style="distributionStyle(details.tentative_count)" />
						<span class="bg-muted/35" :style="distributionStyle(details.unavailable_count)" />
					</div>
					<div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-muted">
						<span>{{ details.available_count }} {{ t('groups.availability.schedule.legend.available') }}</span>
						<span>{{ details.tentative_count }} {{ t('groups.availability.schedule.legend.tentative') }}</span>
						<span>{{ details.unavailable_count }} {{ t('groups.availability.schedule.legend.unavailable') }}</span>
					</div>
				</div>

				<div class="p-4">
					<p class="mb-2 text-xs font-semibold text-highlighted">{{ t('groups.availability.selection.potential_times') }}</p>
					<div class="space-y-1.5">
						<div
							v-for="(overlap, index) in details.potential_overlaps"
							:key="overlap.starts_at"
							class="flex items-center justify-between gap-3 py-1 text-xs"
						>
							<span class="font-medium text-highlighted">{{ formatTime(overlap.starts_at) }} – {{ formatTime(overlap.ends_at) }}</span>
							<div class="flex items-center gap-2">
								<UBadge v-if="index === 0" color="primary" variant="soft" size="sm">{{ t('groups.availability.selection.best') }}</UBadge>
								<span class="text-muted">{{ overlap.available_count + overlap.tentative_count }} / {{ details.total_members }}</span>
							</div>
						</div>
					</div>
				</div>

				<div class="p-4">
					<div class="flex items-center justify-between gap-3">
						<div>
							<p class="text-xs font-semibold text-highlighted">{{ t('groups.availability.selection.can_attend') }}</p>
							<p class="text-[10px] text-muted">{{ t('groups.availability.selection.people', { count: details.members.length }) }}</p>
						</div>
						<UAvatarGroup size="sm" :max="8">
							<UAvatar
								v-for="member in details.members"
								:key="member.id"
								:src="member.avatar_url ?? undefined"
								:alt="member.name"
								:text="initials(member.name)"
							/>
						</UAvatarGroup>
					</div>
				</div>
			</div>
		</UCard>
	</div>
</template>
