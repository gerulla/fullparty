<script setup lang="ts">
import type { RunDiscoveryResultItemData, RunDiscoverySort } from "../../Types/RunDiscovery";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import RunDiscoveryPagination from "@/components/Runs/RunDiscoveryPagination.vue";
import RunDiscoveryResultItem from "@/components/Runs/RunDiscoveryResultItem.vue";

const props = defineProps<{
	items: RunDiscoveryResultItemData[]
	resultCount: number
	currentPage: number
	totalPages: number
	loading?: boolean
	pendingSavedItemIds?: number[]
}>();

const emit = defineEmits<{
	pageChange: [page: number]
	toggleSaved: [item: RunDiscoveryResultItemData]
	sortChange: [sort: RunDiscoverySort]
}>();

const { t } = useI18n();
const selectedSort = ref<RunDiscoverySort>("starting_soonest");
const hasResults = computed(() => props.items.length > 0);

const sortOptions = computed(() => [
	{ label: t("runs.discovery.results.sort_options.starting_soonest"), value: "starting_soonest" },
	{ label: t("runs.discovery.results.sort_options.newest_posted"), value: "newest_posted" },
	{ label: t("runs.discovery.results.sort_options.recently_updated"), value: "recently_updated" },
	{ label: t("runs.discovery.results.sort_options.open_slots"), value: "open_slots" },
]);

watch(selectedSort, (sort) => {
	emit("sortChange", sort);
});
</script>

<template>
	<section class="min-w-0 flex-1 lg:h-full lg:min-h-0">
		<div class="flex flex-col lg:h-full lg:min-h-0 lg:overflow-hidden">
			<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto_auto] lg:items-center lg:gap-6">
				<div class="min-w-0 flex flex-col gap-1">
					<h1 class="text-2xl font-semibold text-white">
						{{ t("runs.discovery.results.title") }}
					</h1>
					<p class="max-w-2xl text-sm leading-6 text-white/60">
						{{ t("runs.discovery.results.subtitle") }}
					</p>
				</div>

				<div class="border-white/8 text-sm font-medium text-white/72 lg:border-l lg:pl-6">
					{{ t("runs.discovery.results.count", { count: resultCount }) }}
				</div>

				<div class="w-full lg:max-w-xs lg:border-l lg:border-white/8 lg:pl-6">
					<USelect
						v-model="selectedSort"
						class="w-full"
						:items="sortOptions"
						value-key="value"
						:placeholder="t('runs.discovery.results.sort_by')"
						:ui="{ base: 'rounded-none' }"
					/>
				</div>
			</div>

			<div class="space-y-4 px-2 py-6 md:px-3 lg:min-h-0 lg:flex-1 lg:overflow-y-auto xl:px-4">
				<div v-if="props.loading" class="space-y-4">
					<div
						v-for="index in 4"
						:key="`run-discovery-skeleton-${index}`"
						class="overflow-hidden border border-white/10 bg-neutral-950/72 shadow-[0_20px_40px_rgba(0,0,0,0.2)]"
					>
						<div class="grid grid-cols-1 2xl:min-h-56 2xl:grid-cols-[8.5rem_minmax(0,1fr)_16rem_13rem] 2xl:items-stretch">
							<USkeleton class="hidden size-full min-h-56 rounded-none 2xl:block" />

							<div class="order-2 space-y-4 p-4 sm:p-5 2xl:order-none">
								<USkeleton class="h-6 w-3/5 rounded-none" />
								<div class="space-y-2">
									<USkeleton class="h-4 w-full rounded-none" />
									<USkeleton class="h-4 w-4/5 rounded-none" />
								</div>
								<div class="flex gap-2">
									<USkeleton class="h-6 w-20 rounded-none" />
									<USkeleton class="h-6 w-24 rounded-none" />
								</div>
							</div>

							<div class="order-1 border-b border-white/8 p-4 sm:p-5 2xl:order-none 2xl:flex 2xl:flex-col 2xl:justify-center 2xl:border-b-0 2xl:border-l">
								<USkeleton class="mb-3 h-3 w-20 rounded-none" />
								<div class="flex items-center gap-4">
									<USkeleton class="size-12 shrink-0 rounded-full 2xl:size-20" />
									<div class="min-w-0 flex-1 space-y-2">
										<USkeleton class="h-5 w-full rounded-none" />
										<USkeleton class="h-4 w-24 rounded-none" />
									</div>
								</div>
								<USkeleton class="mt-5 hidden h-4 w-40 rounded-none 2xl:block" />
							</div>

							<div class="order-3 space-y-4 border-t border-white/8 p-4 sm:p-5 2xl:order-none 2xl:border-l 2xl:border-t-0">
								<div class="space-y-2">
									<USkeleton class="h-4 w-20 rounded-none" />
									<USkeleton class="h-8 w-24 rounded-none" />
									<USkeleton class="h-3 w-14 rounded-none" />
								</div>
								<USkeleton class="h-4 w-28 rounded-none" />
								<div class="grid grid-cols-2 gap-2 2xl:grid-cols-1">
									<USkeleton class="h-9 w-full rounded-none" />
									<USkeleton class="h-9 w-full rounded-none" />
								</div>
							</div>
						</div>
					</div>
				</div>

				<div
					v-else-if="!hasResults"
					class="flex min-h-64 items-center justify-center border border-white/10 bg-neutral-950/42 p-8 text-center"
				>
					<div class="max-w-md space-y-2">
						<p class="text-lg font-semibold text-white">
							{{ t("runs.discovery.results.placeholder_title") }}
						</p>
						<p class="text-sm leading-6 text-white/60">
							{{ t("runs.discovery.results.placeholder_subtitle") }}
						</p>
					</div>
				</div>

				<template v-else>
					<RunDiscoveryResultItem
						v-for="item in props.items"
						:key="item.id"
						:item="item"
						:save-pending="props.pendingSavedItemIds?.includes(item.id) ?? false"
						@toggle-saved="(resultItem) => emit('toggleSaved', resultItem)"
					/>
				</template>
			</div>

			<RunDiscoveryPagination
				v-if="!props.loading && hasResults && props.totalPages > 1"
				:current-page="props.currentPage"
				:total-pages="props.totalPages"
				:disabled="props.loading"
				@page-change="(page) => emit('pageChange', page)"
			/>
		</div>
	</section>
</template>
