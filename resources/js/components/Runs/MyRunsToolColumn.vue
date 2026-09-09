<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { useMediaQuery } from "@vueuse/core";
import { parseDate, type DateValue } from "@internationalized/date";
import { useI18n } from "vue-i18n";
import type { MyRunsGroup, MyRunsToolState } from "@/Types/MyRuns";
import MyRunsWeekCalendar from "@/components/Runs/MyRunsWeekCalendar.vue";
import MyRunsFilterToggles from "@/components/Runs/MyRunsFilterToggles.vue";
import MyRunsGroupPicker from "@/components/Runs/MyRunsGroupPicker.vue";

const props = defineProps<{ groups: MyRunsGroup[]; runDates: string[]; minDate: string }>();
const model = defineModel<MyRunsToolState>({ required: true });
const emit = defineEmits<{ dateSelected: [date: string] }>();
const { t, locale } = useI18n();
const isDesktop = useMediaQuery('(min-width: 1024px)');
const searchOpen = ref(false);
const groupsOpen = ref(false);
const filtersOpen = ref(false);
const searchInput = ref<{ inputRef: HTMLInputElement } | null>(null);
const searchButton = ref<{ $el: HTMLButtonElement } | null>(null);
const updateState = (patch: Partial<MyRunsToolState>) => { model.value = { ...model.value, ...patch }; };
const selectDate = (date: string) => {
	updateState({ date });
	emit('dateSelected', date);
};
const selectedDate = computed({
	get: () => parseDate(model.value.date),
	set: (value: DateValue | undefined) => {
		if (value && value.toString() !== model.value.date) selectDate(value.toString());
	},
});
const filterLabel = computed(() => t(model.value.appliedOnly ? 'my_runs.tools.applied_only' : model.value.hideOverlapping ? 'my_runs.tools.filtered_runs' : 'my_runs.tools.all_runs'));
const groupOptions = computed(() => props.groups.map((group) => ({ label: group.name, value: group.id })));
const filterOptions = computed(() => [
	{ label: t('my_runs.tools.applied_only'), value: 'appliedOnly' as const },
	{ label: t('my_runs.tools.hide_overlapping'), value: 'hideOverlapping' as const },
]);
const selectedFilters = computed({
	get: () => filterOptions.value.filter((option) => model.value[option.value]).map((option) => option.value),
	set: (values: string[]) => updateState({ appliedOnly: values.includes('appliedOnly'), hideOverlapping: values.includes('hideOverlapping') }),
});
const openSearch = async () => {
	groupsOpen.value = false;
	filtersOpen.value = false;
	searchOpen.value = true;
	await nextTick();
	searchInput.value?.inputRef?.focus();
};
const closeSearch = async () => {
	updateState({ search: '' });
	searchOpen.value = false;
	await nextTick();
	searchButton.value?.$el?.focus();
};
watch(isDesktop, () => {
	groupsOpen.value = false;
	filtersOpen.value = false;
	if (model.value.search) searchOpen.value = true;
});
</script>

<template>
	<aside class="flex min-w-0 flex-col border-b border-default pb-4 lg:block lg:border-r lg:border-b-0 lg:pb-6 lg:pr-6" :aria-label="t('my_runs.tools.title')">
		<UInput
			v-if="isDesktop || searchOpen"
			ref="searchInput"
			:model-value="model.search"
			icon="i-lucide-search"
			:placeholder="t('my_runs.tools.search_placeholder')"
			:aria-label="t('my_runs.tools.search_label')"
			class="order-2 mt-3 w-full lg:mt-0 lg:mb-5"
			:ui="{ trailing: 'pe-1', base: 'h-9' }"
			@update:model-value="updateState({ search: String($event ?? '') })"
			@keydown.esc="!isDesktop && closeSearch()"
		>
			<template v-if="model.search || !isDesktop" #trailing>
				<UTooltip :text="t(isDesktop ? 'my_runs.tools.clear_search' : 'my_runs.tools.close_search')">
					<UButton icon="i-lucide-x" color="neutral" variant="ghost" size="xs" :aria-label="t(isDesktop ? 'my_runs.tools.clear_search' : 'my_runs.tools.close_search')" @click="isDesktop ? updateState({ search: '' }) : closeSearch()" />
				</UTooltip>
			</template>
		</UInput>
		<UCalendar
			v-if="isDesktop"
			v-model="selectedDate"
			:locale="locale"
			:min-value="parseDate(minDate)"
			:week-starts-on="1"
			weekday-format="short"
			:year-controls="false"
			:fixed-weeks="false"
			prevent-deselect
			class="w-full"
			:ui="{ headCell: 'text-muted', cellTrigger: 'size-8', heading: 'text-sm' }"
		>
			<template #day="{ day }">
				<span class="relative flex size-full items-center justify-center" @click="day.toString() === model.date && emit('dateSelected', day.toString())">
					{{ day.day }}
					<span v-if="runDates.includes(day.toString())" class="absolute bottom-0.5 size-1 rounded-full bg-warning" aria-hidden="true" />
				</span>
			</template>
		</UCalendar>
		<MyRunsWeekCalendar v-else class="order-1" :date="model.date" :min-date="minDate" :run-dates="runDates" @date-selected="selectDate" />

		<template v-if="isDesktop">
			<USeparator class="my-5" />
			<MyRunsFilterToggles v-model="model" />
			<USeparator class="my-5" />
			<section :aria-label="t('my_runs.tools.groups', { count: groups.length })">
				<h2 class="mb-3 text-sm font-medium text-toned">{{ t('my_runs.tools.groups', { count: groups.length }) }}</h2>
				<MyRunsGroupPicker :model-value="model.groupIds" :groups="groups" @update:model-value="updateState({ groupIds: $event })" />
			</section>
		</template>
		<div v-else-if="!searchOpen" class="order-2 mt-3 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_2.25rem] gap-2">
			<USelectMenu v-model:open="groupsOpen" :model-value="model.groupIds" :items="groupOptions" value-key="value" multiple :search-input="false" color="neutral" class="h-9 min-w-0" :aria-label="t('my_runs.tools.groups', { count: model.groupIds.length })" :ui="{ content: 'min-w-56 max-w-[calc(100vw-2rem)]', itemLabel: 'whitespace-normal wrap-anywhere' }" @update:model-value="updateState({ groupIds: $event })">
				<span class="truncate">{{ t('my_runs.tools.groups', { count: model.groupIds.length }) }}</span>
			</USelectMenu>
			<USelectMenu v-model:open="filtersOpen" v-model="selectedFilters" :items="filterOptions" value-key="value" multiple :search-input="false" color="neutral" class="h-9 min-w-0" :aria-label="t('my_runs.tools.run_filters')" :ui="{ content: 'min-w-56 max-w-[calc(100vw-2rem)]', itemLabel: 'whitespace-normal wrap-anywhere' }">
				<span class="truncate">{{ filterLabel }}</span>
			</USelectMenu>
			<UTooltip :text="t('my_runs.tools.search_label')">
				<UButton ref="searchButton" icon="i-lucide-search" color="neutral" variant="outline" class="size-9 justify-center" :aria-label="t('my_runs.tools.search_label')" @click="openSearch" />
			</UTooltip>
		</div>
	</aside>
</template>
