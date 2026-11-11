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
	create: []
	toggleActive: [payload: { compositionId: number, isActive: boolean }]
	reorder: [compositionIds: number[]]
}>()

const { t } = useI18n()
const k = 'groups.index.content.forked_tower_blood.compositions.'
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
    <aside class="min-w-0 rounded-lg border border-default bg-elevation-1 p-4 shadow-sm dark:bg-elevation-2 dark:shadow-none 2xl:sticky 2xl:top-20">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">{{ t(k + 'workspace.library') }}</h2>
            <UButton size="xs" color="neutral" variant="outline" icon="i-lucide-plus" :label="t(k + 'create')" :disabled="isBusy" class="shrink-0 rounded-md" @click="emit('create')" />
        </header>
        <p class="mt-1 text-xs text-muted">{{ t(k + 'workspace.count', { total: compositions.length, active: compositions.filter(composition => composition.is_active).length }) }}</p>
        <div class="my-4 flex gap-2">
            <UInput v-model="search" icon="i-lucide-search" :placeholder="t(k + 'search_placeholder')" :aria-label="t(k + 'search_placeholder')" class="min-w-0 flex-1" :ui="{ base: 'rounded-md' }" />
            <UPopover>
                <UButton icon="i-lucide-sliders-horizontal" color="neutral" variant="outline" :aria-label="t(k + 'workspace.filter')" class="rounded-md" :class="statusFilter !== 'all' ? 'text-primary' : ''" />
                <template #content>
                    <USelect v-model="statusFilter" :items="filterOptions" :aria-label="t(k + 'workspace.filter')" class="m-3 w-40" :ui="{ base: 'rounded-md' }" />
                </template>
            </UPopover>
        </div>
        <div class="border-t border-default pt-3">
            <div v-if="filteredCompositions.length" class="space-y-1 overflow-y-auto 2xl:max-h-[calc(100dvh-22rem)]">
                <article v-for="composition in filteredCompositions" :key="composition.id" :draggable="!isBusy"
                    class="flex items-center gap-2 rounded-md border-l-4 pr-2 transition-colors"
                    :class="selectedCompositionId === composition.id && !isCreating
                        ? 'border-brand-400 bg-brand-500/15'
                        : dragOverCompositionId === composition.id
                            ? 'border-brand-400 bg-brand-500/10'
                            : 'border-transparent hover:bg-elevation-3'"
                    @dragstart="dragComposition(composition.id, $event)" @dragover="dragOverComposition(composition.id, $event)"
                    @drop.prevent="dropComposition(composition.id, $event)" @dragend="clearDragState">
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-3 px-2 py-3 text-left focus-visible:outline-primary"
                        :disabled="isBusy" :aria-current="selectedCompositionId === composition.id && !isCreating ? 'true' : undefined"
                        @click="emit('select', composition.id)">
                        <UIcon name="i-lucide-layers" class="size-6 shrink-0 text-muted" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold">{{ composition.name }}</span>
                            <span class="mt-0.5 block truncate text-xs text-muted">{{ t(k + 'updated', { time: composition.updated_label }) }}</span>
                        </span>
                    </button>
                    <UIcon v-if="composition.is_default" name="i-lucide-star" class="size-4 shrink-0 text-amber-500" :aria-label="t(k + 'default')" />
                    <USwitch size="xs" :model-value="composition.is_active" :aria-label="t(k + 'active') + ': ' + composition.name" :disabled="isBusy"
                        @update:model-value="value => emit('toggleActive', { compositionId: composition.id, isActive: Boolean(value) })" />
                </article>
            </div>
            <div v-else class="py-8 text-center">
                <p class="text-sm font-medium">{{ t(k + 'empty_title') }}</p>
                <p class="mt-1 text-xs text-muted">{{ t(k + 'empty_description') }}</p>
            </div>
        </div>
        <p class="mt-3 text-xs text-muted">{{ t(k + 'reorder_hint') }}</p>
    </aside>
</template>
