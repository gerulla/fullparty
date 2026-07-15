<script setup lang="ts">
import type { PhantomCompositionPlaceholder } from '@/Types/PhantomComposition'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
	compositions: PhantomCompositionPlaceholder[]
	selectedCompositionId: number | null
	isCreating: boolean
	isBusy?: boolean
}>()

const emit = defineEmits<{
	select: [compositionId: number]
	toggleActive: [payload: { compositionId: number, isActive: boolean }]
	reorder: [compositionIds: number[]]
}>()

const { t } = useI18n()
const search = ref('')
const statusFilter = ref<'all' | 'active' | 'inactive'>('all')
const draggedCompositionId = ref<number | null>(null)
const dragOverCompositionId = ref<number | null>(null)

const filterOptions = computed(() => [
	{ label: t('groups.index.content.forked_tower_blood.compositions.filters.all'), value: 'all' },
	{ label: t('groups.index.content.forked_tower_blood.compositions.filters.active'), value: 'active' },
	{ label: t('groups.index.content.forked_tower_blood.compositions.filters.inactive'), value: 'inactive' },
])

const filteredCompositions = computed(() => {
	const query = search.value.trim().toLowerCase()

	return props.compositions
		.filter((composition) => {
			if (statusFilter.value === 'active') {
				return composition.is_active
			}

			if (statusFilter.value === 'inactive') {
				return !composition.is_active
			}

			return true
		})
		.filter((composition) => query === ''
			|| composition.name.toLowerCase().includes(query)
			|| composition.description.toLowerCase().includes(query))
})

const dragComposition = (compositionId: number, event: DragEvent) => {
	if (props.isBusy) {
		event.preventDefault()
		return
	}

	draggedCompositionId.value = compositionId
	dragOverCompositionId.value = null
	event.dataTransfer?.setData('text/plain', String(compositionId))

	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'move'
	}
}

const dragOverComposition = (compositionId: number, event: DragEvent) => {
	if (draggedCompositionId.value === null || draggedCompositionId.value === compositionId) {
		return
	}

	event.preventDefault()
	dragOverCompositionId.value = compositionId

	if (event.dataTransfer) {
		event.dataTransfer.dropEffect = 'move'
	}
}

const dropComposition = (targetCompositionId: number, event: DragEvent) => {
	if (props.isBusy) {
		return
	}

	const draggedId = draggedCompositionId.value
		?? Number(event.dataTransfer?.getData('text/plain'))

	draggedCompositionId.value = null
	dragOverCompositionId.value = null

	if (!Number.isFinite(draggedId) || draggedId === targetCompositionId) {
		return
	}

	const reorderedCompositions = [...props.compositions]
	const fromIndex = reorderedCompositions.findIndex(composition => composition.id === draggedId)
	const toIndex = reorderedCompositions.findIndex(composition => composition.id === targetCompositionId)

	if (fromIndex === -1 || toIndex === -1) {
		return
	}

	const [composition] = reorderedCompositions.splice(fromIndex, 1)
	reorderedCompositions.splice(toIndex, 0, composition)
	emit('reorder', reorderedCompositions.map(item => item.id))
}

const clearDragState = () => {
	draggedCompositionId.value = null
	dragOverCompositionId.value = null
}
</script>

<template>
	<section class="border border-default bg-muted/20">
		<header class="border-b border-default px-4 py-3">
			<h2 class="font-semibold">
				{{ t('groups.index.content.forked_tower_blood.compositions.title') }}
			</h2>
		</header>

		<div class="space-y-3 p-4">
			<div class="grid grid-cols-[minmax(0,1fr)_7.5rem] gap-2">
				<UInput
					v-model="search"
					icon="i-lucide-search"
					:placeholder="t('groups.index.content.forked_tower_blood.compositions.search_placeholder')"
					class="min-w-0"
					:ui="{ base: 'rounded-none' }"
				/>

				<USelect
					v-model="statusFilter"
					:items="filterOptions"
					value-key="value"
					class="min-w-0"
					:ui="{ base: 'rounded-none' }"
				/>
			</div>

			<div v-if="filteredCompositions.length" class="space-y-2">
				<article
					v-for="composition in filteredCompositions"
					:key="composition.id"
					:draggable="!isBusy"
					class="cursor-grab border bg-background/50 transition active:cursor-grabbing"
					:class="selectedCompositionId === composition.id && !isCreating
						? 'border-primary bg-primary/10 shadow-[inset_2px_0_0_var(--ui-primary)]'
						: dragOverCompositionId === composition.id
							? 'border-primary bg-primary/5'
							: 'border-default hover:border-muted hover:bg-white/[0.03]'"
					@dragstart="dragComposition(composition.id, $event)"
					@dragover="dragOverComposition(composition.id, $event)"
					@drop.prevent="dropComposition(composition.id, $event)"
					@dragend="clearDragState"
				>
					<div class="flex items-center gap-3 p-3">
						<button
							type="button"
							class="min-w-0 flex-1 text-left"
							@click="emit('select', composition.id)"
						>
							<span class="block truncate text-sm font-semibold">
								{{ composition.name }}
							</span>

							<span class="mt-2 flex flex-wrap items-center gap-2">
								<UBadge
									:color="composition.is_active ? 'success' : 'neutral'"
									variant="soft"
									size="sm"
									:label="composition.is_active
										? t('groups.index.content.forked_tower_blood.compositions.active')
										: t('groups.index.content.forked_tower_blood.compositions.inactive')"
									:ui="{ base: 'rounded-none' }"
								/>

								<UBadge
									v-if="composition.is_default"
									color="primary"
									variant="soft"
									size="sm"
									:label="t('groups.index.content.forked_tower_blood.compositions.default')"
									:ui="{ base: 'rounded-none' }"
								/>

								<span class="text-xs text-muted">
									{{ t('groups.index.content.forked_tower_blood.compositions.updated', { time: composition.updated_label }) }}
								</span>
							</span>
						</button>

						<USwitch
							:model-value="composition.is_active"
							:aria-label="composition.name"
							:disabled="isBusy"
							@update:model-value="(value) => emit('toggleActive', {
								compositionId: composition.id,
								isActive: Boolean(value),
							})"
						/>
					</div>
				</article>
			</div>

			<div v-else class="border border-dashed border-default px-4 py-8 text-center">
				<p class="text-sm font-medium">
					{{ t('groups.index.content.forked_tower_blood.compositions.empty_title') }}
				</p>
				<p class="mt-1 text-xs text-muted">
					{{ t('groups.index.content.forked_tower_blood.compositions.empty_description') }}
				</p>
			</div>

			<footer class="flex items-center justify-between gap-3 pt-1 text-xs text-muted">
				<span>
					{{ t('groups.index.content.forked_tower_blood.compositions.count', { count: compositions.length }) }}
				</span>
				<span>{{ t('groups.index.content.forked_tower_blood.compositions.reorder_hint') }}</span>
			</footer>
		</div>
	</section>
</template>
