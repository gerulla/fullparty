<script setup lang="ts">
import type { GlobalSearchResponse, GlobalSearchResult } from "@/Types/GlobalSearch";
import axios from "axios";
import { computed, onBeforeUnmount, ref, useId, watch } from "vue";
import { Link } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

const props = withDefaults(defineProps<{
	autofocus?: boolean
	dropdownMode?: "floating" | "static"
    align?: "start" | "center"
}>(), {
	autofocus: false,
	dropdownMode: "floating",
    align: "start",
});

const emit = defineEmits<{
	selected: []
}>();

const { t, locale } = useI18n({ useScope: "global" });
const { withDisplayTimeZone } = useTimeDisplayMode();
const resultsId = useId();

const emptyResults = (): GlobalSearchResponse => ({
	runs: [],
	past_runs: [],
	groups: [],
	activities: [],
});

const query = ref("");
const results = ref<GlobalSearchResponse>(emptyResults());
const isFocused = ref(false);
const isLoading = ref(false);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;
let closeTimeout: ReturnType<typeof setTimeout> | null = null;
let searchRequestCounter = 0;

const trimmedQuery = computed(() => query.value.trim());
const canSearch = computed(() => trimmedQuery.value.length >= 2);
const hasAnyResults = computed(() => (
	results.value.runs.length > 0
	|| results.value.past_runs.length > 0
	|| results.value.groups.length > 0
	|| results.value.activities.length > 0
));

const resultSections = computed(() => [
	{
		key: "runs",
		title: t("navigation.topbar.global_search.sections.runs"),
		icon: "i-lucide-calendar-days",
		results: results.value.runs,
	},
	{
		key: "past_runs",
		title: t("navigation.topbar.global_search.sections.past_runs"),
		icon: "i-lucide-history",
		results: results.value.past_runs,
	},
	{
		key: "groups",
		title: t("navigation.topbar.global_search.sections.groups"),
		icon: "i-lucide-users",
		results: results.value.groups,
	},
	{
		key: "activities",
		title: t("navigation.topbar.global_search.sections.activities"),
		icon: "i-lucide-swords",
		results: results.value.activities,
	},
]);

const isSurfaceVisible = computed(() => {
	if (props.dropdownMode === "static") {
		return trimmedQuery.value.length > 0;
	}

	return isFocused.value && trimmedQuery.value.length > 0;
});

const surfaceClasses = computed(() => props.dropdownMode === "static"
	? "mt-4 w-full"
	: ["absolute top-full z-50 mt-2 w-[min(44rem,calc(100vw-2rem))]",
        props.align === "center" ? "left-1/2 -translate-x-1/2" : "left-0"]);

const clearSearchTimeout = () => {
	if (searchTimeout !== null) {
		clearTimeout(searchTimeout);
		searchTimeout = null;
	}
};

const clearCloseTimeout = () => {
	if (closeTimeout !== null) {
		clearTimeout(closeTimeout);
		closeTimeout = null;
	}
};

const fetchResults = async () => {
	clearSearchTimeout();

	if (!canSearch.value) {
		results.value = emptyResults();
		isLoading.value = false;
		return;
	}

	const requestId = ++searchRequestCounter;
	isLoading.value = true;

	try {
		const response = await axios.get<GlobalSearchResponse>(route("dashboard.search"), {
			params: {
				query: trimmedQuery.value,
			},
		});

		if (requestId !== searchRequestCounter) {
			return;
		}

		results.value = response.data;
	} catch {
		if (requestId === searchRequestCounter) {
			results.value = emptyResults();
		}
	} finally {
		if (requestId === searchRequestCounter) {
			isLoading.value = false;
		}
	}
};

const scheduleSearch = () => {
	clearSearchTimeout();

	searchTimeout = setTimeout(() => {
		void fetchResults();
	}, 220);
};

const openSurface = () => {
	clearCloseTimeout();
	isFocused.value = true;
};

const scheduleCloseSurface = () => {
	clearCloseTimeout();

	closeTimeout = setTimeout(() => {
		isFocused.value = false;
	}, 120);
};

const formattedMeta = (result: GlobalSearchResult): string | null => {
	if (!result.meta) {
		return null;
	}

	if (result.type !== "run") {
		return result.meta;
	}

	const date = new Date(result.meta);

	if (Number.isNaN(date.getTime())) {
		return null;
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		day: "numeric",
		month: "short",
		hour: "numeric",
		minute: "2-digit",
	})).format(date);
};

const selectResult = (event: MouseEvent) => {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
	query.value = "";
	results.value = emptyResults();
	isFocused.value = false;
	emit("selected");
};

watch(query, () => {
	scheduleSearch();
});

onBeforeUnmount(() => {
	clearSearchTimeout();
	clearCloseTimeout();
});
</script>

<template>
	<div
		class="relative"
        role="search"
		@focusin="openSurface"
		@focusout="scheduleCloseSurface"
	>
		<UInput
			v-model="query"
			:autofocus="autofocus"
			:placeholder="t('navigation.topbar.search_bar')"
            :aria-label="t('navigation.topbar.search_bar')"
            :aria-expanded="isSurfaceVisible"
            :aria-controls="resultsId"
            :loading="isLoading"
            :ui="{ base: 'rounded-md bg-elevation-2 text-default ring-0 placeholder:text-muted hover:bg-elevation-3 focus-visible:ring-2 focus-visible:ring-brand dark:bg-elevation-3 dark:hover:bg-elevation-5', leadingIcon: 'text-muted' }"
			leading-icon="i-lucide-search"
			size="lg"
			class="w-full"
			@keydown.esc="isFocused = false"
		/>

		<div
			v-if="isSurfaceVisible"
            :id="resultsId"
			:class="surfaceClasses"
		>
			<div class="max-h-[min(34rem,calc(100dvh-10rem))] overflow-y-auto rounded-md border border-default bg-elevation-1 p-3 shadow-xl dark:bg-elevation-3">
				<div
					v-if="!canSearch"
					class="px-3 py-6 text-center text-sm text-muted"
				>
					{{ t("navigation.topbar.global_search.min_chars") }}
				</div>

				<div
					v-else-if="isLoading && !hasAnyResults"
					class="space-y-2 px-2 py-2"
				>
					<USkeleton
						v-for="index in 5"
						:key="index"
						class="h-14 rounded-md bg-elevation-2 dark:bg-elevation-5"
					/>
				</div>

				<div
					v-else-if="!hasAnyResults"
					class="px-3 py-6 text-center text-sm text-muted"
                    role="status"
				>
					{{ t("navigation.topbar.global_search.empty") }}
				</div>

				<div
					v-else
					class="space-y-4"
				>
					<section
						v-for="section in resultSections"
						:key="section.key"
						v-show="section.results.length > 0"
						class="space-y-2"
					>
						<div class="flex items-center gap-2 px-2 text-xs font-semibold uppercase tracking-[0.16em] text-muted">
							<UIcon :name="section.icon" class="size-3.5" />
							<span>{{ section.title }}</span>
						</div>

						<div class="space-y-1">
							<Link
								v-for="result in section.results"
								:key="`${result.type}-${result.id}`"
                                :href="result.url"
								class="flex w-full items-center gap-3 rounded-md px-2 py-2.5 text-left transition-colors hover:bg-elevation-2 focus-visible:bg-elevation-2 focus-visible:outline-2 focus-visible:outline-brand dark:hover:bg-elevation-5 dark:focus-visible:bg-elevation-5"
								@click="selectResult"
							>
								<span class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-md bg-elevation-2 dark:bg-elevation-5">
									<img
										v-if="result.image_url"
										:src="result.image_url"
										:alt="result.title"
										class="h-full w-full object-cover"
									>
									<UIcon
										v-else
										:name="result.icon"
										class="size-5 text-brand"
									/>
								</span>

								<span class="min-w-0 flex-1 space-y-1">
									<span class="block truncate text-sm font-semibold text-default">
										{{ result.title }}
									</span>
									<span
										v-if="result.subtitle"
										class="block truncate text-xs text-muted"
									>
										{{ result.subtitle }}
									</span>
								</span>

								<span
									v-if="formattedMeta(result)"
									class="hidden shrink-0 rounded-md bg-elevation-2 px-2 py-1 text-xs text-muted sm:inline-flex dark:bg-elevation-5"
								>
									{{ formattedMeta(result) }}
								</span>

								<UIcon name="i-lucide-arrow-up-right" class="size-4 shrink-0 text-muted" />
							</Link>
						</div>
					</section>
				</div>
			</div>
		</div>
	</div>
</template>
