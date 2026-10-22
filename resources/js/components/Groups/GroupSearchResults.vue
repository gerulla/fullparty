<script setup lang="ts">
import type { GroupIndexRecord, PaginatedGroups } from "@/Types/Groups";
import GroupSearchItem from "@/components/Groups/GroupSearchItem.vue";
import GroupSearchItemSkeleton from "@/components/Groups/GroupSearchItemSkeleton.vue";
import GroupSearchPagination from "@/components/Groups/GroupSearchPagination.vue";
import { useEventListener, useResizeObserver } from "@vueuse/core";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	results: PaginatedGroups
	loading?: boolean
}>();

const emit = defineEmits<{
	pageChange: [page: number]
	pageSizeChange: [pageSize: number]
	openGroup: [group: GroupIndexRecord]
}>();

const { t } = useI18n();
const grid = ref<HTMLElement | null>(null);
const pageSize = ref(6);
const hasMeasuredGrid = ref(false);
const isLoading = computed(() => props.loading || !hasMeasuredGrid.value);

const hasResults = computed(() => props.results.data.length > 0);
const totalPages = computed(() => Math.max(1, props.results.meta.last_page));

function updatePageSize() {
	if (!grid.value || grid.value.clientWidth === 0) {
		return;
	}

	// Read the resolved tracks so pagination follows the actual Tailwind grid.
	const columns = getComputedStyle(grid.value).gridTemplateColumns;
	if (!columns || columns === "none") {
		return;
	}

	const columnCount = columns.trim().split(/\s+/).length;
	const nextPageSize = columnCount === 1 ? 6 : Math.min(60, columnCount * 3);
	if (!hasMeasuredGrid.value || nextPageSize !== pageSize.value) {
		pageSize.value = nextPageSize;
		hasMeasuredGrid.value = true;
		emit("pageSizeChange", nextPageSize);
	}
}

onMounted(updatePageSize);
useResizeObserver(grid, updatePageSize);
useEventListener("resize", updatePageSize);

function onPageChange(page: number) {
	emit("pageChange", page);
}

function onOpenGroup(group: GroupIndexRecord) {
	emit("openGroup", group);
}
</script>

<template>
	<div class="flex flex-col gap-4">
		<div ref="grid" class="grid grid-cols-1 justify-items-center gap-5 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-[repeat(auto-fill,minmax(24rem,1fr))]" :aria-busy="isLoading">
			<template v-if="isLoading">
				<GroupSearchItemSkeleton
					v-for="index in pageSize"
					:key="`group-search-skeleton-${index}`"
				/>
			</template>

			<template v-else>
				<GroupSearchItem
					v-for="group in results.data"
					:key="group.id"
					:group="group"
					@open-group="onOpenGroup"
				/>
			</template>

			<UCard
				v-if="!isLoading && !hasResults"
				class="col-span-full w-full rounded-xl border border-neutral-200 bg-elevation-1 text-sm text-muted ring-0 dark:border-default dark:bg-elevation-2"
				:ui="{ body: 'p-5 sm:p-5' }"
			>
				{{ t("general.no_results") }}
			</UCard>
		</div>

		<GroupSearchPagination
			v-if="!isLoading && hasResults"
			:current-page="results.meta.current_page"
			:total-pages="totalPages"
			:disabled="isLoading"
			@page-change="onPageChange"
		/>
	</div>
</template>
