<script setup lang="ts">
import type { BozjaHolsterSummary } from '@/Types/Bozja'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import BozjaHolsterCard from './BozjaHolsterCard.vue'

const props = defineProps<{
	holsters: BozjaHolsterSummary[]
	updatingHolsterIds: number[]
}>()

const emit = defineEmits<{
	edit: [holsterId: number]
	duplicate: [holster: BozjaHolsterSummary]
	toggleActive: [payload: { holsterId: number, isActive: boolean }]
	makeDefault: [holsterId: number]
	delete: [holster: BozjaHolsterSummary]
}>()

const { t } = useI18n()
const search = ref('')
const statusFilter = ref<'all' | 'active' | 'inactive'>('all')
const expandedPrepopIds = ref<number[]>([])

const filterOptions = computed(() => [
	{ label: t('groups.index.content.delubrum_reginae_savage.holsters.filters.all'), value: 'all' },
	{ label: t('groups.index.content.delubrum_reginae_savage.holsters.filters.active'), value: 'active' },
	{ label: t('groups.index.content.delubrum_reginae_savage.holsters.filters.inactive'), value: 'inactive' },
])

const prepopHolsters = computed(() => props.holsters.filter(holster => holster.type === 'prepop'))
const activeCount = computed(() => prepopHolsters.value.filter(holster => holster.is_active).length)
const filteredHolsters = computed(() => {
	const query = search.value.trim().toLowerCase()

	return prepopHolsters.value
		.filter(holster => statusFilter.value === 'all'
			|| (statusFilter.value === 'active' ? holster.is_active : !holster.is_active))
		.filter(holster => query === '' || [
			holster.display_name,
			holster.notes,
			...Object.values(holster.name ?? {}),
			...holster.items.flatMap(item => [item.display_name, ...Object.values(item.name ?? {})]),
		].some(value => String(value ?? '').toLowerCase().includes(query)))
})

const refillsFor = (prepopId: number) => props.holsters.filter(
	holster => holster.type === 'refill' && holster.parent_holster_id === prepopId,
)

const isExpanded = (prepopId: number) => expandedPrepopIds.value.includes(prepopId)

const toggleRefills = (prepopId: number) => {
	expandedPrepopIds.value = isExpanded(prepopId)
		? expandedPrepopIds.value.filter(id => id !== prepopId)
		: [...expandedPrepopIds.value, prepopId]
}

const refillTrayClass = (prepopId: number) => {
	const refillCount = refillsFor(prepopId).length

	return [
		'relative isolate grid min-w-0 grid-cols-1 gap-4',
		refillCount >= 2 ? 'sm:col-span-2 sm:grid-cols-2' : '',
		refillCount >= 3 ? 'lg:col-span-3 lg:grid-cols-3' : '',
		refillCount >= 4 ? 'xl:col-span-4 xl:grid-cols-4' : '',
	]
}
</script>

<template>
	<section class="space-y-4">
		<div class="flex flex-col gap-3 border-b border-default pb-4 lg:flex-row lg:items-center lg:justify-between">
			<p class="text-sm text-muted">
				{{ t('groups.index.content.delubrum_reginae_savage.holsters.available_summary', {
					total: prepopHolsters.length,
					active: activeCount,
				}) }}
			</p>

			<div class="flex w-full gap-2 lg:w-auto">
				<UInput
					v-model="search"
					icon="i-lucide-search"
					:placeholder="t('groups.index.content.delubrum_reginae_savage.holsters.search_placeholder')"
					class="min-w-0 flex-1 lg:w-72"
				/>
				<USelect
					v-model="statusFilter"
					:items="filterOptions"
					value-key="value"
					class="w-32 shrink-0"
				/>
			</div>
		</div>

		<div v-if="filteredHolsters.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
			<template
				v-for="holster in filteredHolsters"
				:key="holster.id"
			>
				<BozjaHolsterCard
					class="min-w-0"
					:holster="holster"
					:updating="updatingHolsterIds.includes(holster.id)"
					:refill-count="refillsFor(holster.id).length"
					:refills-expanded="isExpanded(holster.id)"
					@edit="emit('edit', $event)"
					@duplicate="emit('duplicate', $event)"
					@toggle-active="emit('toggleActive', $event)"
					@make-default="emit('makeDefault', $event)"
					@delete="emit('delete', $event)"
					@toggle-refills="toggleRefills(holster.id)"
				/>

				<div
					v-if="isExpanded(holster.id)"
					:class="refillTrayClass(holster.id)"
				>
					<div class="pointer-events-none absolute inset-y-0 -left-4 right-0 -z-10 border border-default bg-accented/55 shadow-inner" />
					<BozjaHolsterCard
						v-for="refill in refillsFor(holster.id)"
						:key="refill.id"
						class="min-w-0"
						:holster="refill"
						:updating="updatingHolsterIds.includes(refill.id)"
						@edit="emit('edit', $event)"
						@duplicate="emit('duplicate', $event)"
						@toggle-active="emit('toggleActive', $event)"
						@make-default="emit('makeDefault', $event)"
						@delete="emit('delete', $event)"
					/>
				</div>
			</template>
		</div>

		<div v-else class="border border-dashed border-default px-4 py-12 text-center">
			<UIcon name="i-lucide-briefcase" class="mx-auto size-7 text-muted" />
			<p class="mt-2 text-sm font-medium">
				{{ t('groups.index.content.delubrum_reginae_savage.holsters.empty_title') }}
			</p>
			<p class="mt-1 text-xs text-muted">
				{{ t('groups.index.content.delubrum_reginae_savage.holsters.empty_description') }}
			</p>
		</div>
	</section>
</template>
