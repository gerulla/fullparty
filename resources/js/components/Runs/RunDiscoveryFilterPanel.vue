<script setup lang="ts">
import type {
	RunDiscoveryActivityTypeOption,
	RunDiscoveryClassOption,
	RunDiscoveryDateRange,
	RunDiscoveryFilterState,
	RunDiscoveryLookupOption,
	RunDiscoveryLookups,
	RunDiscoveryRoleCategory,
} from "../../Types/RunDiscovery";
import type { DateValue } from "@internationalized/date";
import type { DateRange } from "reka-ui";
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import RunDiscoveryClassPickerModal from "@/components/Runs/RunDiscoveryClassPickerModal.vue";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

const props = defineProps<{
	lookups: RunDiscoveryLookups
}>();

const emit = defineEmits<{
	"filters-change": [filters: RunDiscoveryFilterState]
}>();

const { t, locale } = useI18n();
const { displayTimeZone, displayTimeZoneLabel } = useTimeDisplayMode();

const searchQuery = ref("");
const savedOnly = ref(false);
const selectedActivityCategory = ref("any");
const selectedActivityType = ref("any");
const selectedProgPoint = ref("any");
const selectedRunStyle = ref("any");
const beginnerFriendlyOnly = ref(false);
const selectedRoleCategory = ref<RunDiscoveryRoleCategory>("any");
const selectedClassKeys = ref<string[]>([]);
const isClassPickerOpen = ref(false);
const selectedGroupType = ref("community");
const selectedApplicationStatus = ref<string | null>(null);
const selectedIntensity = ref<string | null>(null);
const selectedVoiceExpectation = ref<string | null>(null);
const selectedDateRange = ref<RunDiscoveryDateRange>("upcoming");
const customRange = ref<DateRange | null>(null);
const customDayDate = ref<DateValue | undefined>(undefined);
const isCustomRangeOpen = ref(false);
const isCustomDayOpen = ref(false);
const selectedRegion = ref("any");
const selectedDatacenter = ref("all");
const selectedGroup = ref("any");
const selectedTimeOfDay = ref("any");
const selectedLanguage = ref("any");
const detectedTimezone = ref("");
const isAdvancedFiltersOpen = ref(false);

const activityTypeDefinitions = computed<RunDiscoveryActivityTypeOption[]>(() => props.lookups.activity_types ?? []);
const classOptions = computed<RunDiscoveryClassOption[]>(() => props.lookups.class_options ?? []);

const activityCategoryOptions = computed<RunDiscoveryLookupOption[]>(() => {
	const availableCategories = Array.from(new Set(
		activityTypeDefinitions.value
			.map((option) => option.difficulty)
			.filter((value): value is string => typeof value === "string" && value !== ""),
	)).sort();

	return [
		{ label: t("runs.discovery.filters.options.any.activity_category"), value: "any" },
		...availableCategories.map((value) => ({
			label: t(`runs.discovery.filters.options.activity_categories.${value}`),
			value,
		})),
	];
});

const filteredActivityTypeDefinitions = computed<RunDiscoveryActivityTypeOption[]>(() => {
	if (selectedActivityCategory.value === "any") {
		return activityTypeDefinitions.value;
	}

	return activityTypeDefinitions.value.filter((option) => option.difficulty === selectedActivityCategory.value);
});

const activityTypeOptions = computed<RunDiscoveryLookupOption[]>(() => [
	{ label: t("runs.discovery.filters.options.any.activity_type"), value: "any" },
	...filteredActivityTypeDefinitions.value.map(({ label, value }) => ({ label, value })),
]);

const selectedActivityTypeDefinition = computed(() => activityTypeDefinitions.value.find((option) => option.value === selectedActivityType.value) ?? null);
const selectedActivityTypeHasProgPoints = computed(() => selectedActivityType.value !== "any" && Boolean(selectedActivityTypeDefinition.value?.prog_points?.length));
const progPointOptions = computed<RunDiscoveryLookupOption[]>(() => {
	if (!selectedActivityTypeHasProgPoints.value) {
		return [];
	}

	return [
		{ label: t("runs.discovery.filters.options.any.prog_point"), value: "any" },
		...(selectedActivityTypeDefinition.value?.prog_points ?? []),
	];
});

const quickDateRangeOptions = computed<RunDiscoveryLookupOption[]>(() => [
	{ label: t("runs.discovery.filters.options.date_ranges.next_7_days"), value: "next_7_days" },
	{ label: t("runs.discovery.filters.options.date_ranges.next_30_days"), value: "next_30_days" },
]);

const roleCategoryOptions = computed(() => [
	{ label: t("runs.discovery.filters.options.roles.any"), value: "any", icon: "i-lucide-layout-grid" },
	{ label: t("runs.discovery.filters.options.roles.tank"), value: "tank", icon: "i-lucide-shield" },
	{ label: t("runs.discovery.filters.options.roles.healer"), value: "healer", icon: "i-lucide-plus" },
	{ label: t("runs.discovery.filters.options.roles.dps"), value: "dps", icon: "i-lucide-sword" },
]);

const groupTypeOptions = computed<RunDiscoveryLookupOption[]>(() => [
	{ label: t("runs.discovery.filters.options.group_types.community"), value: "community" },
	{ label: t("runs.discovery.filters.options.group_types.static"), value: "static" },
]);

const applicationStatusOptions = computed<RunDiscoveryLookupOption[]>(() => [
	{ label: t("runs.discovery.filters.options.application_statuses.open_short"), value: "applications_open" },
	{ label: t("runs.discovery.filters.options.application_statuses.direct_join"), value: "direct_join" },
]);

const datacenterLookups = computed(() => props.lookups.datacenters ?? []);

const regionOptions = computed<RunDiscoveryLookupOption[]>(() => [
	{ label: t("runs.discovery.filters.options.any.region"), value: "any" },
	...(props.lookups.regions ?? []),
]);

const datacenterOptions = computed<RunDiscoveryLookupOption[]>(() => [
	{ label: t("runs.discovery.filters.options.any.data_center"), value: "all" },
	...datacenterLookups.value.map(({ label, value }) => ({ label, value })),
]);

const groupOptions = computed<RunDiscoveryLookupOption[]>(() => [
	{ label: t("runs.discovery.filters.options.any.group"), value: "any" },
	...(props.lookups.groups ?? []),
]);

const languageOptions = computed<RunDiscoveryLookupOption[]>(() => [
	{ label: t("runs.discovery.filters.options.any.language"), value: "any" },
	...(props.lookups.languages ?? []),
]);

const runStyleOptions = computed<RunDiscoveryLookupOption[]>(() => [
	{ label: t("runs.discovery.filters.options.any.run_style"), value: "any" },
	...(props.lookups.run_styles ?? []).map((value) => ({
		label: t(`runs.discovery.filters.options.run_styles.${value}`),
		value,
	})),
]);

const timeOfDayOptions = computed<RunDiscoveryLookupOption[]>(() => [
	{ label: t("runs.discovery.filters.options.time_of_day.any"), value: "any" },
	{ label: t("runs.discovery.filters.options.time_of_day.morning"), value: "morning" },
	{ label: t("runs.discovery.filters.options.time_of_day.afternoon"), value: "afternoon" },
	{ label: t("runs.discovery.filters.options.time_of_day.evening"), value: "evening" },
	{ label: t("runs.discovery.filters.options.time_of_day.night"), value: "night" },
]);

const intensityOptions = computed<RunDiscoveryLookupOption[]>(() => [
	...(props.lookups.intensities ?? []).map((value) => ({
		label: t(`runs.discovery.filters.options.intensity.${value}`),
		value,
	})),
]);

const voiceExpectationOptions = computed<RunDiscoveryLookupOption[]>(() => [
	...(props.lookups.voice_expectations ?? []).map((value) => ({
		label: t(`runs.discovery.filters.options.voice_expectations.${value}`),
		value,
	})),
]);

const visibleClassOptions = computed(() => {
	if (selectedRoleCategory.value === "tank") {
		return classOptions.value.filter((option) => option.group === "tank");
	}

	if (selectedRoleCategory.value === "healer") {
		return classOptions.value.filter((option) => option.group === "healer");
	}

	if (selectedRoleCategory.value === "dps") {
		return classOptions.value.filter((option) => ["melee", "phys", "magic"].includes(option.group));
	}

	return classOptions.value;
});

const selectedClassOptions = computed(() => classOptions.value.filter((option) => selectedClassKeys.value.includes(option.key)));
const isDateTimeFilterClear = computed(() => (
	selectedDateRange.value === "upcoming"
	&& selectedTimeOfDay.value === "any"
	&& customRange.value === null
	&& customDayDate.value === undefined
));

const formatDateLabel = (date: string) => {
	if (date === "") {
		return "";
	}

	return new Intl.DateTimeFormat(locale.value, {
		day: "numeric",
		month: "short",
	}).format(new Date(`${date}T00:00:00`));
};

const customRangeDates = computed<[string | null, string | null]>(() => {
	const startDate = customRange.value?.start?.toString() ?? "";
	const endDate = customRange.value?.end?.toString() ?? "";

	if (startDate === "" || endDate === "") {
		return [null, null];
	}

	if (startDate <= endDate) {
		return [startDate, endDate];
	}

	return [endDate, startDate];
});

const customDay = computed(() => customDayDate.value?.toString() ?? "");

const effectiveDateRange = computed<RunDiscoveryDateRange>(() => {
	if (selectedDateRange.value === "custom_day") {
		return customDay.value === "" ? "upcoming" : "custom_day";
	}

	if (selectedDateRange.value === "custom_range") {
		const [start, end] = customRangeDates.value;

		return start && end ? "custom_range" : "upcoming";
	}

	return selectedDateRange.value;
});

const customRangeLabel = computed(() => {
	const [start, end] = customRangeDates.value;

	if (!start || !end) {
		return t("runs.discovery.filters.options.date_ranges.custom_range");
	}

	return `${formatDateLabel(start)} - ${formatDateLabel(end)}`;
});

const customDayLabel = computed(() => (
	customDay.value === ""
		? t("runs.discovery.filters.options.date_ranges.custom_day")
		: formatDateLabel(customDay.value)
));

const classSummaryLabel = computed(() => {
	if (selectedClassOptions.value.length === 0) {
		return t("runs.discovery.filters.class_picker.empty");
	}

	if (selectedClassOptions.value.length === 1) {
		return selectedClassOptions.value[0]?.label ?? t("runs.discovery.filters.class_picker.empty");
	}

	return t("runs.discovery.filters.class_picker.selected_count", { count: selectedClassOptions.value.length });
});

const selectRoleCategory = (value: RunDiscoveryRoleCategory) => {
	selectedRoleCategory.value = value;
	selectedClassKeys.value = [];
};

const selectQuickDateRange = (value: RunDiscoveryDateRange) => {
	selectedDateRange.value = value;
	customRange.value = null;
	customDayDate.value = undefined;
};

const openCustomRangePicker = () => {
	selectedDateRange.value = "custom_range";
	customDayDate.value = undefined;
	isCustomRangeOpen.value = true;
};

const openCustomDayPicker = () => {
	selectedDateRange.value = "custom_day";
	customRange.value = null;
	isCustomDayOpen.value = true;
};

const handleCustomRangeChange = (value: DateRange | null) => {
	selectedDateRange.value = "custom_range";
	customRange.value = value;
	customDayDate.value = undefined;

	if (value?.start && value.end) {
		isCustomRangeOpen.value = false;
	}
};

const handleCustomDayChange = (value: DateValue | undefined) => {
	selectedDateRange.value = "custom_day";
	customDayDate.value = value;
	customRange.value = null;

	if (value) {
		isCustomDayOpen.value = false;
	}
};

const clearDateTimeFilters = () => {
	selectedDateRange.value = "upcoming";
	customRange.value = null;
	customDayDate.value = undefined;
	selectedTimeOfDay.value = "any";
};

const filterState = computed<RunDiscoveryFilterState>(() => ({
	query: searchQuery.value,
	saved_only: savedOnly.value,
	activity_category: selectedActivityCategory.value,
	activity_type: selectedActivityType.value,
	prog_point: selectedProgPoint.value,
	region: selectedRegion.value,
	datacenter: selectedDatacenter.value,
	group: selectedGroup.value,
	timezone: displayTimeZone.value || detectedTimezone.value || "UTC",
	date_range: effectiveDateRange.value,
	date_from: selectedDateRange.value === "custom_day"
		? (customDay.value || null)
		: selectedDateRange.value === "custom_range"
			? customRangeDates.value[0]
			: null,
	date_to: selectedDateRange.value === "custom_range" ? customRangeDates.value[1] : null,
	time_of_day: selectedTimeOfDay.value as RunDiscoveryFilterState["time_of_day"],
	run_style: selectedRunStyle.value,
	beginner_friendly: beginnerFriendlyOnly.value,
	language: selectedLanguage.value,
	role_category: selectedRoleCategory.value,
	class_keys: [...selectedClassKeys.value],
	group_type: selectedGroupType.value,
	application_status: selectedApplicationStatus.value,
	intensity: selectedIntensity.value,
	voice_expectation: selectedVoiceExpectation.value,
}));

watch(selectedActivityCategory, () => {
	if (selectedActivityType.value === "any") {
		return;
	}

	if (!filteredActivityTypeDefinitions.value.some((option) => option.value === selectedActivityType.value)) {
		selectedActivityType.value = "any";
		selectedProgPoint.value = "any";
	}
});

watch(
	() => [selectedActivityType.value, progPointOptions.value] as const,
	() => {
		if (!selectedActivityTypeHasProgPoints.value) {
			selectedProgPoint.value = "any";

			return;
		}

		const validValues = new Set(progPointOptions.value.map((option) => option.value));

		if (!validValues.has(selectedProgPoint.value)) {
			selectedProgPoint.value = "any";
		}
	},
	{ immediate: true },
);

watch(selectedDatacenter, (datacenter) => {
	if (datacenter === "all") {
		selectedRegion.value = "any";

		return;
	}

	const matchingDatacenter = datacenterLookups.value.find((option) => option.value === datacenter);

	if (matchingDatacenter?.region) {
		selectedRegion.value = matchingDatacenter.region;
	}
});

watch(selectedClassKeys, (classKeys) => {
	if (classKeys.length > 0) {
		selectedRoleCategory.value = null;

		return;
	}

	if (selectedRoleCategory.value === null) {
		selectedRoleCategory.value = "any";
	}
});

watch(
	filterState,
	(currentFilters) => {
		emit("filters-change", currentFilters);
	},
);

const applyInitialFiltersFromUrl = () => {
	if (typeof window === "undefined") {
		return;
	}

	const params = new URL(window.location.href).searchParams;
	const activityCategory = params.get("activity_category");
	const activityTypeId = params.get("activity_type_id");
	const activityType = activityTypeDefinitions.value
		.find((option) => String(option.id) === activityTypeId)?.value ?? params.get("activity_type");
	const group = props.lookups.groups.find((option) => option.value === params.get("group"));

	if (activityCategory && activityCategoryOptions.value.some((option) => option.value === activityCategory)) {
		selectedActivityCategory.value = activityCategory;
	}

	if (activityType && activityTypeDefinitions.value.some((option) => option.value === activityType)) {
		selectedActivityType.value = activityType;
	}

	if (group) {
		selectedGroup.value = group.value;
		selectedGroupType.value = group.group_type;
	}
};

onMounted(() => {
	applyInitialFiltersFromUrl();
	detectedTimezone.value = Intl.DateTimeFormat().resolvedOptions().timeZone
		|| t("runs.discovery.filters.placeholders.timezone");
	emit("filters-change", filterState.value);
});

const resetFilters = () => {
	searchQuery.value = "";
	savedOnly.value = false;
	selectedActivityCategory.value = "any";
	selectedActivityType.value = "any";
	selectedProgPoint.value = "any";
	selectedRunStyle.value = "any";
	beginnerFriendlyOnly.value = false;
	selectedRoleCategory.value = "any";
	selectedClassKeys.value = [];
	selectedGroupType.value = "community";
	selectedApplicationStatus.value = null;
	selectedIntensity.value = null;
	selectedVoiceExpectation.value = null;
	selectedDateRange.value = "upcoming";
	customRange.value = null;
	customDayDate.value = undefined;
	selectedRegion.value = "any";
	selectedDatacenter.value = "all";
	selectedGroup.value = "any";
	selectedTimeOfDay.value = "any";
	selectedLanguage.value = "any";
};
</script>

<template>
	<aside class="w-full lg:h-full lg:max-w-[22rem] lg:shrink-0">
		<div class="flex min-h-0 flex-col overflow-hidden border border-white/10 bg-neutral-950/82 shadow-[0_24px_48px_rgba(0,0,0,0.32)] lg:h-full">
			<div class="min-h-0 space-y-6 overflow-y-auto px-5 py-5 lg:flex-1">
				<section class="space-y-3">
					<div class="flex items-center justify-between gap-3">
						<p class="text-sm font-semibold text-white">
							{{ t("runs.discovery.filters.sections.saved_runs") }}
						</p>
						<USwitch v-model="savedOnly" />
					</div>
				</section>

				<section class="space-y-3">
					<div class="flex items-center justify-between gap-3">
						<p class="text-sm font-semibold text-white">
							{{ t("runs.discovery.filters.sections.search") }}
						</p>
					</div>

					<UInput
						v-model="searchQuery"
						icon="i-lucide-search"
						class="w-full"
						:placeholder="t('runs.discovery.filters.search_placeholder')"
						:ui="{ base: 'rounded-none' }"
					/>
				</section>

				<UButton
					color="neutral"
					variant="outline"
					class="w-full justify-between rounded-none border-white/10 bg-neutral-950/50 text-white/72 hover:bg-neutral-900 lg:hidden"
					:label="t('runs.discovery.filters.title')"
					:trailing-icon="isAdvancedFiltersOpen ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
					@click="isAdvancedFiltersOpen = !isAdvancedFiltersOpen"
				/>

				<div
					class="space-y-6"
					:class="isAdvancedFiltersOpen ? 'block' : 'hidden lg:block'"
				>
					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.activity_type") }}
							</p>
						</div>

						<USelectMenu
							v-model="selectedActivityCategory"
							class="w-full"
							:items="activityCategoryOptions"
							value-key="value"
							:placeholder="t('runs.discovery.filters.options.any.activity_category')"
						/>

						<USelectMenu
							v-model="selectedActivityType"
							class="w-full"
							:items="activityTypeOptions"
							value-key="value"
							:placeholder="t('runs.discovery.filters.options.any.activity_type')"
						/>

						<USelectMenu
							v-if="selectedActivityTypeHasProgPoints"
							v-model="selectedProgPoint"
							class="w-full"
							:items="progPointOptions"
							value-key="value"
							:placeholder="t('runs.discovery.filters.options.any.prog_point')"
						/>
					</section>

					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.datacenter_world") }}
							</p>
						</div>

						<div class="space-y-3">
							<USelect
								v-model="selectedRegion"
								class="w-full"
								:items="regionOptions"
								value-key="value"
								:ui="{ base: 'rounded-none' }"
							/>
							<USelect
								v-model="selectedDatacenter"
								class="w-full"
								:items="datacenterOptions"
								value-key="value"
								:ui="{ base: 'rounded-none' }"
							/>
						</div>
					</section>

					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.group") }}
							</p>
						</div>

						<USelect
							v-model="selectedGroup"
							class="w-full"
							:items="groupOptions"
							value-key="value"
							:ui="{ base: 'rounded-none' }"
						/>
					</section>

					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.day_time") }}
							</p>
							<button
								type="button"
								class="text-xs uppercase tracking-[0.14em] transition-colors"
								:class="isDateTimeFilterClear ? 'text-brand-300' : 'text-white/38 hover:text-white/68'"
								@click="clearDateTimeFilters"
							>
								{{ t("runs.discovery.filters.clear") }}
							</button>
						</div>

						<div class="grid grid-cols-2 gap-2">
							<UButton
								v-for="option in quickDateRangeOptions"
								:key="option.value"
								color="neutral"
								:variant="selectedDateRange === option.value ? 'solid' : 'outline'"
								class="justify-center rounded-none"
								:class="selectedDateRange === option.value ? 'bg-brand-600 hover:bg-brand-500 border-brand-400/70 text-white' : 'border-white/10 bg-neutral-950/50 text-white/72 hover:bg-neutral-900'"
								:label="option.label"
								@click="selectQuickDateRange(option.value as RunDiscoveryDateRange)"
							/>

							<UPopover
								v-model:open="isCustomRangeOpen"
								:content="{ side: 'bottom', align: 'start', collisionPadding: 8 }"
							>
								<template #default>
									<UButton
										color="neutral"
										:variant="selectedDateRange === 'custom_range' ? 'solid' : 'outline'"
										class="justify-center rounded-none"
										:class="selectedDateRange === 'custom_range' ? 'bg-brand-600 hover:bg-brand-500 border-brand-400/70 text-white' : 'border-white/10 bg-neutral-950/50 text-white/72 hover:bg-neutral-900'"
										:label="customRangeLabel"
										@click="openCustomRangePicker"
									/>
								</template>

								<template #content>
									<div class="border border-white/10 bg-neutral-950 p-3">
										<UCalendar
											range
											:model-value="customRange"
											:week-starts-on="1"
											:year-controls="false"
											color="primary"
											class="min-w-72"
											@update:model-value="handleCustomRangeChange"
										/>
									</div>
								</template>
							</UPopover>

							<UPopover
								v-model:open="isCustomDayOpen"
								:content="{ side: 'bottom', align: 'start', collisionPadding: 8 }"
							>
								<template #default>
									<UButton
										color="neutral"
										:variant="selectedDateRange === 'custom_day' ? 'solid' : 'outline'"
										class="justify-center rounded-none"
										:class="selectedDateRange === 'custom_day' ? 'bg-brand-600 hover:bg-brand-500 border-brand-400/70 text-white' : 'border-white/10 bg-neutral-950/50 text-white/72 hover:bg-neutral-900'"
										:label="customDayLabel"
										@click="openCustomDayPicker"
									/>
								</template>

								<template #content>
									<div class="border border-white/10 bg-neutral-950 p-3">
										<UCalendar
											:model-value="customDayDate"
											:week-starts-on="1"
											:year-controls="false"
											:prevent-deselect="true"
											color="primary"
											class="min-w-72"
											@update:model-value="handleCustomDayChange"
										/>
									</div>
								</template>
							</UPopover>
						</div>

						<USelect
							v-model="selectedTimeOfDay"
							class="w-full"
							:items="timeOfDayOptions"
							value-key="value"
							:ui="{ base: 'rounded-none' }"
						/>

						<div class="flex items-center justify-between border border-white/10 bg-neutral-950/50 px-3 py-2.5 text-sm text-white/66">
							<span>{{ displayTimeZoneLabel || detectedTimezone || t("runs.discovery.filters.placeholders.timezone") }}</span>
							<UIcon name="i-lucide-globe" class="size-4 text-white/38" />
						</div>
					</section>

					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.run_style") }}
							</p>
						</div>

						<USelect
							v-model="selectedRunStyle"
							class="w-full"
							:items="runStyleOptions"
							value-key="value"
							:ui="{ base: 'rounded-none' }"
						/>

						<div class="flex items-center justify-between gap-4 mt-2">
							<p class="text-sm font-medium text-white">
								{{ t("runs.discovery.filters.labels.beginner_friendly") }}
							</p>
							<USwitch v-model="beginnerFriendlyOnly" />
						</div>
					</section>

					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.language") }}
							</p>
						</div>

						<USelect
							v-model="selectedLanguage"
							class="w-full"
							:items="languageOptions"
							value-key="value"
							:ui="{ base: 'rounded-none' }"
						/>
					</section>

					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.role_needed") }}
							</p>
						</div>

						<div class="grid grid-cols-4 gap-2">
							<UButton
								v-for="option in roleCategoryOptions"
								:key="option.value"
								color="neutral"
								:variant="selectedRoleCategory === option.value ? 'solid' : 'outline'"
								class="flex-col items-center justify-center gap-2 rounded-none px-3 py-3"
								:class="selectedRoleCategory === option.value ? 'bg-brand-600 hover:bg-brand-500 border-brand-400/70 text-white' : 'border-white/10 bg-neutral-950/50 text-white/72 hover:bg-neutral-900'"
								:icon="option.icon"
								:label="option.label"
								@click="selectRoleCategory(option.value as RunDiscoveryRoleCategory)"
							/>
						</div>

						<div class="space-y-2">
							<UButton
								color="neutral"
								variant="outline"
								class="w-full justify-between rounded-none"
								:label="classSummaryLabel"
								trailing-icon="i-lucide-chevron-down"
								@click="isClassPickerOpen = true"
							/>

							<div
								v-if="selectedClassOptions.length > 0"
								class="flex flex-wrap gap-2"
							>
								<UBadge
									v-for="option in selectedClassOptions"
									:key="option.key"
									color="neutral"
									variant="soft"
									:label="option.shorthand"
								/>
							</div>
						</div>
					</section>

					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.group_type") }}
							</p>
						</div>

						<div class="grid grid-cols-2 gap-2">
							<UButton
								v-for="option in groupTypeOptions"
								:key="option.value"
								color="neutral"
								:variant="selectedGroupType === option.value ? 'solid' : 'outline'"
								class="justify-center rounded-none"
								:disabled="option.value === 'static'"
								:class="option.value === 'static'
									? 'border-white/8 bg-neutral-950/35 text-white/28 opacity-60'
									: selectedGroupType === option.value
										? 'bg-brand-600 hover:bg-brand-500 border-brand-400/70 text-white'
										: 'border-white/10 bg-neutral-950/50 text-white/72 hover:bg-neutral-900'"
								:label="option.label"
								@click="selectedGroupType = option.value"
							/>
						</div>
					</section>

					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.application_status") }}
							</p>
							<button
								type="button"
								class="text-xs uppercase tracking-[0.14em] transition-colors"
								:class="selectedApplicationStatus === null ? 'text-brand-300' : 'text-white/38 hover:text-white/68'"
								@click="selectedApplicationStatus = null"
							>
								{{ t("runs.discovery.filters.clear") }}
							</button>
						</div>

						<div class="grid grid-cols-2 gap-2">
							<UButton
								v-for="option in applicationStatusOptions"
								:key="option.value"
								color="neutral"
								:variant="selectedApplicationStatus === option.value ? 'solid' : 'outline'"
								class="justify-center rounded-none"
								:class="selectedApplicationStatus === option.value ? 'bg-brand-600 hover:bg-brand-500 border-brand-400/70 text-white' : 'border-white/10 bg-neutral-950/50 text-white/72 hover:bg-neutral-900'"
								:label="option.label"
								@click="selectedApplicationStatus = option.value"
							/>
						</div>
					</section>

					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.intensity") }}
							</p>
							<button
								type="button"
								class="text-xs uppercase tracking-[0.14em] transition-colors"
								:class="selectedIntensity === null ? 'text-brand-300' : 'text-white/38 hover:text-white/68'"
								@click="selectedIntensity = null"
							>
								{{ t("runs.discovery.filters.clear") }}
							</button>
						</div>

						<div class="grid grid-cols-3 gap-2">
							<UButton
								v-for="option in intensityOptions"
								:key="option.value"
								color="neutral"
								:variant="selectedIntensity === option.value ? 'solid' : 'outline'"
								class="justify-center rounded-none"
								:class="selectedIntensity === option.value ? 'bg-brand-600 hover:bg-brand-500 border-brand-400/70 text-white' : 'border-white/10 bg-neutral-950/50 text-white/72 hover:bg-neutral-900'"
								:label="option.label"
								@click="selectedIntensity = option.value"
							/>
						</div>
					</section>

					<section class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-semibold text-white">
								{{ t("runs.discovery.filters.sections.voice_expectation") }}
							</p>
							<button
								type="button"
								class="text-xs uppercase tracking-[0.14em] transition-colors"
								:class="selectedVoiceExpectation === null ? 'text-brand-300' : 'text-white/38 hover:text-white/68'"
								@click="selectedVoiceExpectation = null"
							>
								{{ t("runs.discovery.filters.clear") }}
							</button>
						</div>

						<div class="space-y-2">
							<UButton
								v-for="option in voiceExpectationOptions"
								:key="option.value"
								color="neutral"
								:variant="selectedVoiceExpectation === option.value ? 'solid' : 'outline'"
								class="w-full justify-start rounded-none"
								:class="selectedVoiceExpectation === option.value ? 'bg-brand-600 hover:bg-brand-500 border-brand-400/70 text-white' : 'border-white/10 bg-neutral-950/50 text-white/72 hover:bg-neutral-900'"
								:label="option.label"
								@click="selectedVoiceExpectation = option.value"
							/>
						</div>
					</section>

					<div class="pt-2">
						<UButton
							color="neutral"
							variant="ghost"
							icon="i-lucide-rotate-ccw"
							class="rounded-none px-0 text-white/68 hover:text-white"
							:label="t('runs.discovery.filters.reset')"
							@click="resetFilters"
						/>
					</div>
				</div>
			</div>
		</div>
	</aside>

	<RunDiscoveryClassPickerModal
		v-model:open="isClassPickerOpen"
		v-model:selected-keys="selectedClassKeys"
		:options="visibleClassOptions"
		:filter-role="selectedRoleCategory"
	/>
</template>
