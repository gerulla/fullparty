<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
	PlannerEditorTool,
	RaidPlanMarkerKey,
} from '../../types/RaidPlan'
import {
	raidPlanMarkerAssets,
	raidPlanMarkerKeys,
} from '../../utils/markerAssets'

const { t } = useI18n()
const selectedTool = defineModel<PlannerEditorTool>({ default: 'select' })
const props = defineProps<{
	canAddElements: boolean
}>()
const emit = defineEmits<{
	addBoss: []
	addMarker: [marker: RaidPlanMarkerKey]
}>()
const markerPickerOpen = ref(false)

const tools = computed(() => [
	{ value: 'select' as const, label: t('planner.editor.tools.select'), icon: 'i-lucide-mouse-pointer-2' },
	{ value: 'boss' as const, label: t('planner.editor.tools.boss'), icon: 'i-lucide-skull' },
	{ value: 'player' as const, label: t('planner.editor.tools.player'), icon: 'i-lucide-user-round' },
	{ value: 'markers' as const, label: t('planner.editor.tools.markers'), icon: 'i-lucide-map-pin' },
	{ value: 'text' as const, label: t('planner.editor.tools.text'), icon: 'i-lucide-type' },
])

watch(markerPickerOpen, (open) => {
	if (open) {
		selectedTool.value = 'markers'
	} else if (selectedTool.value === 'markers') {
		selectedTool.value = 'select'
	}
})

const useTool = (tool: PlannerEditorTool): void => {
	if (tool === 'boss') {
		emit('addBoss')
		selectedTool.value = 'select'

		return
	}

	if (tool === 'markers') {
		markerPickerOpen.value = true

		return
	}

	selectedTool.value = tool
}

const addMarker = (marker: RaidPlanMarkerKey): void => {
	emit('addMarker', marker)
	markerPickerOpen.value = false
	selectedTool.value = 'select'
}
</script>

<template>
	<div
		class="absolute left-3 top-3 z-20 flex flex-col border border-default bg-muted p-1 shadow-xl"
	>
		<template v-for="tool in tools" :key="tool.value">
			<UPopover
				v-if="tool.value === 'markers'"
				v-model:open="markerPickerOpen"
				:content="{ side: 'right', align: 'start', sideOffset: 8 }"
			>
				<UTooltip :text="tool.label" :content="{ side: 'right' }">
					<UButton
						:icon="tool.icon"
						:color="selectedTool === tool.value ? 'primary' : 'neutral'"
						:variant="selectedTool === tool.value ? 'soft' : 'ghost'"
						size="lg"
						square
						:disabled="!props.canAddElements"
						:aria-label="tool.label"
						@click="useTool(tool.value)"
					/>
				</UTooltip>

				<template #content>
					<div class="grid grid-cols-2 gap-1 p-2">
						<UTooltip
							v-for="marker in raidPlanMarkerKeys"
							:key="marker"
							:text="t('planner.editor.tools.marker', { marker })"
							:content="{ side: 'right' }"
						>
							<button
								type="button"
								class="flex size-14 items-center justify-center border border-transparent hover:border-primary hover:bg-accented"
								:aria-label="t('planner.editor.tools.marker', { marker })"
								@click="addMarker(marker)"
							>
								<img
									:src="raidPlanMarkerAssets[marker]"
									:alt="t('planner.editor.tools.marker', { marker })"
									class="size-10 object-contain"
								>
							</button>
						</UTooltip>
					</div>
				</template>
			</UPopover>

			<UTooltip v-else :text="tool.label" :content="{ side: 'right' }">
				<UButton
					:icon="tool.icon"
					:color="selectedTool === tool.value ? 'primary' : 'neutral'"
					:variant="selectedTool === tool.value ? 'soft' : 'ghost'"
					size="lg"
					square
					:disabled="tool.value === 'boss' && !props.canAddElements"
					:aria-label="tool.label"
					@click="useTool(tool.value)"
				/>
			</UTooltip>
		</template>
	</div>
</template>
