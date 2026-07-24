<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PlannerZoom } from '../../composables/usePlannerCanvasViewport'

const { t } = useI18n()
const zoom = defineModel<PlannerZoom>('zoom', { default: 'fit' })
const props = defineProps<{
	planName: string
	canEdit: boolean
	saving: boolean
}>()
const emit = defineEmits<{
	editPlan: []
	save: []
}>()
const showGrid = ref(true)
const snapToGrid = ref(true)

const zoomOptions = computed(() => [
	{ label: '50%', value: 50 },
	{ label: '75%', value: 75 },
	{ label: '100%', value: 100 },
	{ label: '125%', value: 125 },
	{ label: '150%', value: 150 },
	{ label: '200%', value: 200 },
	{ label: t('planner.editor.toolbar.fit'), value: 'fit' },
	{ label: t('planner.editor.toolbar.fill'), value: 'fill' },
])
</script>

<template>
	<div class="grid grid-cols-[1fr_auto_1fr] items-center border-b border-default bg-elevated px-3">
		<div class="flex items-center gap-1">
			<UTooltip :text="t('planner.navigation.undo')">
				<UButton icon="i-lucide-undo-2" color="neutral" variant="ghost" disabled />
			</UTooltip>
			<UTooltip :text="t('planner.navigation.redo')">
				<UButton icon="i-lucide-redo-2" color="neutral" variant="ghost" disabled />
			</UTooltip>
			<USeparator orientation="vertical" class="mx-2 h-5" />
			<UButton
				:label="t('planner.editor.toolbar.grid')"
				icon="i-lucide-grid-3x3"
				:color="showGrid ? 'primary' : 'neutral'"
				:variant="showGrid ? 'soft' : 'ghost'"
				@click="showGrid = !showGrid"
			/>
			<UButton
				:label="t('planner.editor.toolbar.snap')"
				icon="i-lucide-magnet"
				:color="snapToGrid ? 'primary' : 'neutral'"
				:variant="snapToGrid ? 'soft' : 'ghost'"
				@click="snapToGrid = !snapToGrid"
			/>
		</div>

		<div class="flex min-w-0 items-center justify-center gap-1 px-4">
			<p class="max-w-80 truncate text-sm font-semibold text-highlighted">
				{{ props.planName }}
			</p>

			<UButton
				v-if="props.canEdit"
				icon="i-lucide-pencil"
				color="neutral"
				variant="ghost"
				size="xs"
				:aria-label="t('planner.editor.toolbar.edit_plan')"
				@click="emit('editPlan')"
			/>
		</div>

		<div class="flex items-center justify-end gap-2">
			<USelect v-model="zoom" :items="zoomOptions" value-key="value" class="w-24" />
			<UButton
				:label="t('planner.editor.toolbar.play')"
				icon="i-lucide-play"
				color="primary"
				disabled
			/>
			<UButton
				:label="t('planner.editor.toolbar.save')"
				icon="i-lucide-save"
				color="neutral"
				variant="solid"
				:disabled="!props.canEdit"
				:loading="props.saving"
				@click="emit('save')"
			/>
		</div>
	</div>
</template>
