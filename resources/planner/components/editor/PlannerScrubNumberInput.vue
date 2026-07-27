<script setup lang="ts">
import { computed, ref } from 'vue'

const model = defineModel<number>({ required: true })
const props = withDefaults(defineProps<{
	ariaLabel: string
	min?: number
	max?: number
	step?: number
	disabled?: boolean
}>(), {
	min: Number.NEGATIVE_INFINITY,
	max: Number.POSITIVE_INFINITY,
	step: 1,
	disabled: false,
})

const root = ref<HTMLElement | null>(null)
const pointerId = ref<number | null>(null)
const startX = ref(0)
const startValue = ref(0)
const dragging = ref(false)
const pixelsPerStep = 4

const inputCursor = computed(() => (
	dragging.value ? 'cursor-ew-resize select-none' : 'cursor-ew-resize'
))

const clamp = (value: number): number => (
	Math.min(props.max, Math.max(props.min, value))
)

const precision = (): number => {
	const decimal = String(props.step).split('.')[1]

	return decimal?.length ?? 0
}

const updateValue = (value: string | number): void => {
	const number = Number(value)

	if (Number.isFinite(number)) {
		model.value = Number(clamp(number).toFixed(precision()))
	}
}

const startScrub = (event: PointerEvent): void => {
	if (props.disabled || event.button !== 0) {
		return
	}

	pointerId.value = event.pointerId
	startX.value = event.clientX
	startValue.value = model.value
	dragging.value = false
	root.value?.setPointerCapture(event.pointerId)
}

const moveScrub = (event: PointerEvent): void => {
	if (pointerId.value !== event.pointerId) {
		return
	}

	const distance = event.clientX - startX.value

	if (!dragging.value && Math.abs(distance) < 3) {
		return
	}

	dragging.value = true
	event.preventDefault()
	updateValue(startValue.value + Math.round(distance / pixelsPerStep) * props.step)
}

const stopScrub = (event: PointerEvent): void => {
	if (pointerId.value !== event.pointerId) {
		return
	}

	const wasDragging = dragging.value

	if (root.value?.hasPointerCapture(event.pointerId)) {
		root.value.releasePointerCapture(event.pointerId)
	}
	pointerId.value = null
	dragging.value = false

	if (wasDragging) {
		event.preventDefault()
		root.value?.querySelector('input')?.blur()
	}
}
</script>

<template>
	<div
		ref="root"
		:class="inputCursor"
		@pointerdown="startScrub"
		@pointermove="moveScrub"
		@pointerup="stopScrub"
		@pointercancel="stopScrub"
	>
		<UInput
			:model-value="model"
			type="number"
			:min="props.min"
			:max="props.max"
			:step="props.step"
			:disabled="props.disabled"
			:aria-label="props.ariaLabel"
			class="w-full"
			:ui="{ base: inputCursor }"
			@update:model-value="updateValue"
		/>
	</div>
</template>

<style scoped>
:deep(input[type='number']) {
	appearance: textfield;
}

:deep(input[type='number']::-webkit-inner-spin-button),
:deep(input[type='number']::-webkit-outer-spin-button) {
	margin: 0;
	appearance: none;
}
</style>
