<script setup lang="ts">
import type { DatacenterLookup, GroupDiscoveryLookups, GroupIndexRecord, GroupJoinMode, PaginatedGroups } from "@/Types/Groups";
import GroupSearchResults from "@/components/Groups/GroupSearchResults.vue";
import axios from "axios";
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

type TopLevelGroupFilter = "all" | "community" | "static"
type MemberCountFilter = "1" | "50" | "100" | "500" | null
type SortFilter =
	| "created_at_desc"
	| "created_at_asc"
	| "active_at_desc"
	| "active_at_asc"
	| "member_count_desc"
	| "member_count_asc"

type SharedPageProps = {
	discoverGroups?: PaginatedGroups
	lookups?: {
		datacenters?: DatacenterLookup[]
		group_discovery?: GroupDiscoveryLookups
	}
	locale?: {
		current?: string
	}
}

const { t } = useI18n();
const filterInputUi = {
	base: "rounded-md border-neutral-300 bg-elevation-1 text-default placeholder:text-muted ring-neutral-300 focus-visible:ring-brand-700 dark:border-default dark:bg-elevation-3 dark:ring-default dark:focus-visible:ring-brand-400",
	leadingIcon: "text-muted",
	trailingIcon: "text-muted",
};
const filterSelectUi = {
	...filterInputUi,
	placeholder: "text-muted",
	content: "rounded-lg bg-elevation-1 shadow-lg ring-neutral-200 dark:bg-elevation-3 dark:ring-default",
	item: "rounded-md text-default data-highlighted:bg-elevation-2 dark:data-highlighted:bg-elevation-5",
};
const page = usePage<SharedPageProps>();
const emit = defineEmits<{
	openGroup: [group: GroupIndexRecord]
}>();
const emptyResults: PaginatedGroups = {
	data: [],
	meta: {
		current_page: 1,
		last_page: 1,
		per_page: 6,
		total: 0,
	},
};

const selectedTopLevelFilter = ref<TopLevelGroupFilter>("all");
const searchQuery = ref("");
const experienceFilter = ref<string | undefined>(undefined);
const regionFilter = ref<string | undefined>(undefined);
const memberCountFilter = ref<MemberCountFilter>(null);
const sortBy = ref<SortFilter>("created_at_desc");
const isMoreFiltersOpen = ref(false);

const recruitingMethodFilter = ref<GroupJoinMode | undefined>(undefined);
const primaryFocusFilters = ref<string[]>([]);
const voiceExpectationFilter = ref<string | undefined>(undefined);
const preferredLanguageFilters = ref<string[]>([]);
const activeDayFilters = ref<string[]>([]);
const extraTagsFilter = ref("");
const currentPage = ref(page.props.discoverGroups?.meta.current_page ?? 1);
const resultsPerPage = ref(page.props.discoverGroups?.meta.per_page ?? emptyResults.meta.per_page);
const discoveryResults = ref<PaginatedGroups>(page.props.discoverGroups ?? emptyResults);
const isLoadingResults = ref(false);
let searchRequestCounter = 0;
let scheduledFetchTimeout: ReturnType<typeof setTimeout> | null = null;

const groupDiscoveryLookups = computed<GroupDiscoveryLookups>(() => page.props.lookups?.group_discovery ?? {});
const datacenterLookups = computed<DatacenterLookup[]>(() => page.props.lookups?.datacenters ?? []);

const topLevelItems = computed(() => [
	{
		label: t("groups.index.discovery.tabs.all"),
		value: "all",
	},
	{
		label: t("groups.index.discovery.tabs.communities"),
		value: "community",
	},
	{
		label: t("groups.index.discovery.tabs.statics"),
		value: "static",
	},
]);

const experienceOptions = computed(() => (groupDiscoveryLookups.value.experience_expectations ?? []).map((value) => ({
	label: t(`groups.index.create_modal.fields.experience_expectation.options.${value}`),
	value,
})));

const regionOptions = computed(() => {
	const uniqueRegions = Array.from(new Set(
		datacenterLookups.value
			.map((datacenter) => datacenter.region)
			.filter((region): region is string => Boolean(region)),
	));

	return uniqueRegions.map((value) => ({
		label: value,
		value,
	}));
});

const memberCountOptions = computed(() => ([
	{
		label: t("groups.index.discovery.filters.size_options.1"),
		value: "1",
	},
	{
		label: t("groups.index.discovery.filters.size_options.50"),
		value: "50",
	},
	{
		label: t("groups.index.discovery.filters.size_options.100"),
		value: "100",
	},
	{
		label: t("groups.index.discovery.filters.size_options.500"),
		value: "500",
	},
]));

const recruitingMethodOptions = computed(() => ([
	{
		label: t("groups.index.discovery.filters.recruiting_methods.open"),
		value: "open",
	},
	{
		label: t("groups.index.discovery.filters.recruiting_methods.invite_only"),
		value: "invite_only",
	},
]));

const primaryFocusOptions = computed(() => (groupDiscoveryLookups.value.primary_focuses ?? []).map((value) => ({
	label: t(`groups.index.create_modal.fields.primary_focuses.options.${value}`),
	value,
})));

const voiceExpectationOptions = computed(() => (groupDiscoveryLookups.value.voice_expectations ?? []).map((value) => ({
	label: t(`groups.common.voice_expectations.${value}`),
	value,
})));

const preferredLanguageOptions = computed(() => (groupDiscoveryLookups.value.preferred_languages ?? []).map((value) => ({
	label: resolveLanguageLabel(value),
	value,
})));

const activeDayOptions = computed(() => (groupDiscoveryLookups.value.active_days ?? []).map((value) => ({
	label: t(`groups.common.active_days.${value}`),
	value,
})));

const sortByOptions = computed(() => ([
	{
		label: t("groups.index.discovery.filters.sort_options.created_at_desc"),
		value: "created_at_desc",
	},
	{
		label: t("groups.index.discovery.filters.sort_options.created_at_asc"),
		value: "created_at_asc",
	},
	{
		label: t("groups.index.discovery.filters.sort_options.active_at_desc"),
		value: "active_at_desc",
	},
	{
		label: t("groups.index.discovery.filters.sort_options.active_at_asc"),
		value: "active_at_asc",
	},
	{
		label: t("groups.index.discovery.filters.sort_options.member_count_desc"),
		value: "member_count_desc",
	},
	{
		label: t("groups.index.discovery.filters.sort_options.member_count_asc"),
		value: "member_count_asc",
	},
]));

const hasActiveFilters = computed(() => (
	selectedTopLevelFilter.value !== "all"
	|| searchQuery.value.trim() !== ""
	|| Boolean(experienceFilter.value)
	|| Boolean(regionFilter.value)
	|| Boolean(memberCountFilter.value)
	|| sortBy.value !== "created_at_desc"
	|| Boolean(recruitingMethodFilter.value)
	|| primaryFocusFilters.value.length > 0
	|| Boolean(voiceExpectationFilter.value)
	|| preferredLanguageFilters.value.length > 0
	|| activeDayFilters.value.length > 0
	|| extraTagsFilter.value.trim() !== ""
));
const requestParams = computed(() => ({
	query: searchQuery.value.trim() || undefined,
	group_type: selectedTopLevelFilter.value === "all" ? undefined : selectedTopLevelFilter.value,
	experience_expectation: experienceFilter.value || undefined,
	region: regionFilter.value || undefined,
	size: memberCountFilter.value || undefined,
	sort_by: sortBy.value,
	join_mode: recruitingMethodFilter.value || undefined,
	primary_focuses: primaryFocusFilters.value,
	voice_expectation: voiceExpectationFilter.value || undefined,
	preferred_languages: preferredLanguageFilters.value,
	active_days: activeDayFilters.value,
	extra_tags: extraTagsFilter.value.trim() || undefined,
	page: currentPage.value,
	per_page: resultsPerPage.value,
}));
const filterSignature = computed(() => JSON.stringify({
	query: requestParams.value.query ?? null,
	group_type: requestParams.value.group_type ?? null,
	experience_expectation: requestParams.value.experience_expectation ?? null,
	region: requestParams.value.region ?? null,
	size: requestParams.value.size ?? null,
	sort_by: requestParams.value.sort_by,
	join_mode: requestParams.value.join_mode ?? null,
	primary_focuses: [...primaryFocusFilters.value],
	voice_expectation: requestParams.value.voice_expectation ?? null,
	preferred_languages: [...preferredLanguageFilters.value],
	active_days: [...activeDayFilters.value],
	extra_tags: requestParams.value.extra_tags ?? null,
	per_page: requestParams.value.per_page,
}));

function resolveLanguageLabel(value: string) {
	const languageLabels: Record<string, string> = {
		en: "English",
		de: "Deutsch",
		fr: "Français",
		ja: "日本語",
	};

	return languageLabels[value] ?? value.toUpperCase();
}

function clearFilters() {
	selectedTopLevelFilter.value = "all";
	searchQuery.value = "";
	experienceFilter.value = undefined;
	regionFilter.value = undefined;
	memberCountFilter.value = null;
	sortBy.value = "created_at_desc";
	recruitingMethodFilter.value = undefined;
	primaryFocusFilters.value = [];
	voiceExpectationFilter.value = undefined;
	preferredLanguageFilters.value = [];
	activeDayFilters.value = [];
	extraTagsFilter.value = "";
	currentPage.value = 1;
}

async function fetchDiscoveryResults() {
	if (scheduledFetchTimeout !== null) {
		clearTimeout(scheduledFetchTimeout);
		scheduledFetchTimeout = null;
	}

	const requestId = ++searchRequestCounter;
	isLoadingResults.value = true;

	try {
		const response = await axios.get<PaginatedGroups>(route("groups.search"), {
			params: requestParams.value,
		});

		if (requestId !== searchRequestCounter) {
			return;
		}

		discoveryResults.value = response.data;
	} catch {
		// Keep the last successful results visible if a discovery request fails.
	} finally {
		if (requestId === searchRequestCounter) {
			isLoadingResults.value = false;
		}
	}
}

function scheduleFetch() {
	// Ignore any older response while filters or grid sizing are settling.
	searchRequestCounter++;
	isLoadingResults.value = true;

	if (scheduledFetchTimeout !== null) {
		clearTimeout(scheduledFetchTimeout);
	}

	scheduledFetchTimeout = setTimeout(() => {
		scheduledFetchTimeout = null;
		void fetchDiscoveryResults();
	}, 250);
}

function onPageSizeChange(pageSize: number) {
	if (pageSize === resultsPerPage.value) {
		return;
	}

	resultsPerPage.value = pageSize;
	isLoadingResults.value = true;
}

watch(filterSignature, () => {
	if (currentPage.value !== 1) {
		currentPage.value = 1;

		return;
	}

	scheduleFetch();
});

watch(currentPage, (pageNumber, previousPageNumber) => {
	if (pageNumber === previousPageNumber) {
		return;
	}

	void fetchDiscoveryResults();
});

onBeforeUnmount(() => {
	searchRequestCounter++;
	if (scheduledFetchTimeout !== null) {
		clearTimeout(scheduledFetchTimeout);
	}
});
</script>

<template>
	<section class="mt-10 flex flex-col gap-5">
		<div class="flex flex-col gap-2">
			<h2 class="text-xl font-semibold text-highlighted">{{ t('groups.index.discovery.title') }}</h2>
			<p class="text-sm text-muted">{{ t('groups.index.discovery.subtitle') }}</p>
		</div>

		<div class="flex flex-col gap-3 border-b border-neutral-200 pb-3 dark:border-default md:flex-row md:items-center md:justify-between">
			<UTabs
				v-model="selectedTopLevelFilter"
				:items="topLevelItems"
				:content="false"
				variant="link"
				:ui="{ list: 'border-0', trigger: 'dark:data-[state=active]:text-brand-400', indicator: 'dark:bg-brand-400' }"
				size="xl"
				class="min-w-0 md:w-auto"
			/>
			<USelectMenu
				v-model="sortBy"
				:items="sortByOptions"
				value-key="value"
				size="lg"
				class="w-full md:w-80 md:shrink-0"
				:ui="filterSelectUi"
				:search-input="{ ui: filterInputUi }"
				variant="outline"
				:placeholder="t('groups.index.discovery.filters.sort_placeholder')"
				:aria-label="t('groups.index.discovery.filters.sort_placeholder')"
			/>
		</div>

		<div>
			<div class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 xl:grid-cols-2">
				<UInput
					v-model="searchQuery"
					:placeholder="t('groups.index.discovery.filters.search_placeholder')"
					icon="i-lucide-search"
					:maxlength="255"
					size="lg"
					class="w-full min-w-0"
					:ui="filterInputUi"
					variant="outline"
				/>
				<div class="grid gap-3 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.8fr)_minmax(0,0.8fr)_auto]">
					<USelectMenu
						v-model="experienceFilter"
						:items="experienceOptions"
						value-key="value"
						size="lg"
						class="hidden w-full min-w-0 xl:inline-flex"
						:ui="filterSelectUi"
						:search-input="{ ui: filterInputUi }"
						variant="outline"
						:placeholder="t('groups.index.discovery.filters.experience_placeholder')"
						:aria-label="t('groups.index.discovery.filters.experience_placeholder')"
					/>
					<USelectMenu
						v-model="regionFilter"
						:items="regionOptions"
						value-key="value"
						size="lg"
						class="hidden w-full min-w-0 xl:inline-flex"
						:ui="filterSelectUi"
						:search-input="{ ui: filterInputUi }"
						variant="outline"
						:placeholder="t('groups.index.discovery.filters.region_placeholder')"
						:aria-label="t('groups.index.discovery.filters.region_placeholder')"
					/>
					<USelectMenu
						v-model="memberCountFilter"
						:items="memberCountOptions"
						value-key="value"
						size="lg"
						class="hidden w-full min-w-0 xl:inline-flex"
						:ui="filterSelectUi"
						:search-input="{ ui: filterInputUi }"
						variant="outline"
						:placeholder="t('groups.index.discovery.filters.size_placeholder')"
						:aria-label="t('groups.index.discovery.filters.size_placeholder')"
					/>
					<UButton
						color="neutral"
						variant="outline"
						size="lg"
						trailing-icon="i-lucide-sliders-horizontal"
						class="justify-center rounded-md border-neutral-300 bg-elevation-1 text-default ring-neutral-300 hover:bg-elevation-2 dark:border-default dark:bg-elevation-3 dark:ring-default dark:hover:bg-elevation-4"
						:aria-expanded="isMoreFiltersOpen"
						@click="isMoreFiltersOpen = !isMoreFiltersOpen"
					>
						<span class="sr-only md:not-sr-only xl:hidden">{{ t('groups.index.discovery.filters.toggle') }}</span>
						<span class="hidden xl:inline">{{ t('groups.index.discovery.filters.more_filters_open') }}</span>
					</UButton>
				</div>
			</div>

			<UCollapsible v-model:open="isMoreFiltersOpen" :class="isMoreFiltersOpen ? 'mt-4' : undefined">
				<template #content>
					<div class="grid gap-4 border-t border-neutral-200 dark:border-default py-4 md:grid-cols-2 xl:grid-cols-3">
						<UFormField
							class="xl:hidden"
							:ui="{ label: 'text-muted' }"
							:label="t('groups.index.discovery.filters.experience_placeholder')"
						>
							<USelectMenu
								v-model="experienceFilter"
								:items="experienceOptions"
								value-key="value"
								size="lg"
								class="w-full"
								:ui="filterSelectUi"
								:search-input="{ ui: filterInputUi }"
								variant="outline"
								:placeholder="t('groups.index.discovery.filters.experience_placeholder')"
								:aria-label="t('groups.index.discovery.filters.experience_placeholder')"
							/>
						</UFormField>
						<UFormField
							class="xl:hidden"
							:ui="{ label: 'text-muted' }"
							:label="t('groups.index.discovery.filters.region_placeholder')"
						>
							<USelectMenu
								v-model="regionFilter"
								:items="regionOptions"
								value-key="value"
								size="lg"
								class="w-full"
								:ui="filterSelectUi"
								:search-input="{ ui: filterInputUi }"
								variant="outline"
								:placeholder="t('groups.index.discovery.filters.region_placeholder')"
								:aria-label="t('groups.index.discovery.filters.region_placeholder')"
							/>
						</UFormField>
						<UFormField
							class="xl:hidden"
							:ui="{ label: 'text-muted' }"
							:label="t('groups.index.discovery.filters.size_placeholder')"
						>
							<USelectMenu
								v-model="memberCountFilter"
								:items="memberCountOptions"
								value-key="value"
								size="lg"
								class="w-full"
								:ui="filterSelectUi"
								:search-input="{ ui: filterInputUi }"
								variant="outline"
								:placeholder="t('groups.index.discovery.filters.size_placeholder')"
								:aria-label="t('groups.index.discovery.filters.size_placeholder')"
							/>
						</UFormField>
						<UFormField
							:ui="{ label: 'text-muted' }"
							:label="t('groups.index.discovery.filters.recruiting_method_label')"
						>
							<USelectMenu
								v-model="recruitingMethodFilter"
								:items="recruitingMethodOptions"
								value-key="value"
								class="w-full"
								:ui="filterSelectUi"
								:search-input="{ ui: filterInputUi }"
								variant="outline"
								:placeholder="t('groups.index.discovery.filters.recruiting_method_placeholder')"
							/>
						</UFormField>

						<UFormField
							:ui="{ label: 'text-muted' }"
							:label="t('groups.index.create_modal.fields.primary_focuses.label')"
						>
							<USelectMenu
								v-model="primaryFocusFilters"
								:items="primaryFocusOptions"
								value-key="value"
								multiple
								class="w-full"
								:ui="filterSelectUi"
								:search-input="{ ui: filterInputUi }"
								variant="outline"
								:placeholder="t('groups.index.create_modal.fields.primary_focuses.placeholder')"
							/>
						</UFormField>

						<UFormField
							:ui="{ label: 'text-muted' }"
							:label="t('groups.index.create_modal.fields.voice_expectation.label')"
						>
							<USelectMenu
								v-model="voiceExpectationFilter"
								:items="voiceExpectationOptions"
								value-key="value"
								class="w-full"
								:ui="filterSelectUi"
								:search-input="{ ui: filterInputUi }"
								variant="outline"
								:placeholder="t('groups.index.create_modal.fields.voice_expectation.placeholder')"
							/>
						</UFormField>

						<UFormField
							:ui="{ label: 'text-muted' }"
							:label="t('groups.index.create_modal.fields.preferred_languages.label')"
						>
							<USelectMenu
								v-model="preferredLanguageFilters"
								:items="preferredLanguageOptions"
								value-key="value"
								multiple
								class="w-full"
								:ui="filterSelectUi"
								:search-input="{ ui: filterInputUi }"
								variant="outline"
								:placeholder="t('groups.index.create_modal.fields.preferred_languages.placeholder')"
							/>
						</UFormField>

						<UFormField
							:ui="{ label: 'text-muted' }"
							:label="t('groups.index.create_modal.fields.active_days.label')"
						>
							<USelectMenu
								v-model="activeDayFilters"
								:items="activeDayOptions"
								value-key="value"
								multiple
								class="w-full"
								:ui="filterSelectUi"
								:search-input="{ ui: filterInputUi }"
								variant="outline"
								:placeholder="t('groups.index.create_modal.fields.active_days.placeholder')"
							/>
						</UFormField>

						<UFormField
							:ui="{ label: 'text-muted' }"
							:label="t('groups.index.create_modal.fields.tags.label')"
						>
							<UInput
								v-model="extraTagsFilter"
								:placeholder="t('groups.index.discovery.filters.tags_placeholder')"
								icon="i-lucide-tags"
								:maxlength="255"
								class="w-full"
								:ui="filterInputUi"
								variant="outline"
							/>
						</UFormField>
					</div>
					<div class="flex justify-end">
						<UButton
							color="neutral"
							variant="ghost"
							icon="i-lucide-filter-x"
							class="justify-center rounded-md text-muted hover:text-highlighted"
							:disabled="!hasActiveFilters"
							@click="clearFilters"
						>
							{{ t('groups.index.discovery.filters.clear_filters') }}
						</UButton>
					</div>
				</template>
			</UCollapsible>
		</div>

		<GroupSearchResults
			:results="discoveryResults"
			:loading="isLoadingResults"
			@page-change="currentPage = $event"
			@page-size-change="onPageSizeChange"
			@open-group="emit('openGroup', $event)"
		/>
	</section>
</template>
