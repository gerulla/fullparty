<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import {
	usePlannerCanvasViewport,
	type PlannerZoom,
} from '../../composables/usePlannerCanvasViewport'
import { PlannerRenderer } from '../../core/rendering/PlannerRenderer'
import { EditorPlaceholderScene } from '../../core/rendering/scenes/EditorPlaceholderScene'

const props = defineProps<{
	zoom: PlannerZoom
}>()

const { t } = useI18n()
const viewport = ref<HTMLElement | null>(null)
const canvasHost = ref<HTMLElement | null>(null)
const renderer = new PlannerRenderer(
	new EditorPlaceholderScene(),
	t('planner.editor.canvas_label'),
)
const {
	stageStyle,
	viewportCursor,
	startPan,
	movePan,
	stopPan,
} = usePlannerCanvasViewport(viewport, toRef(props, 'zoom'))

onMounted(async () => {
	if (canvasHost.value) {
		await renderer.mount(canvasHost.value)
	}
})

onBeforeUnmount(() => {
	renderer.destroy()
})
</script>

<template>
	<div
		ref="viewport"
		:class="[
			'relative size-full touch-none overflow-hidden',
			viewportCursor,
		]"
		@pointerdown="startPan"
		@pointermove="movePan"
		@pointerup="stopPan"
		@pointercancel="stopPan"
	>
		<div
			ref="canvasHost"
			class="absolute left-1/2 top-1/2 overflow-hidden bg-neutral-950 shadow-lg ring-1 ring-inset ring-default"
			:style="stageStyle"
		/>
	</div>
</template>
