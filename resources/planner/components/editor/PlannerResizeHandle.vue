<script setup lang="ts">
const props = withDefaults(defineProps<{
	orientation: 'horizontal' | 'vertical'
	label: string
	invert?: boolean
}>(), {
	invert: false,
})

const emit = defineEmits<{
	pointerdown: [event: PointerEvent]
	adjust: [delta: number]
}>()

const handleKeydown = (event: KeyboardEvent): void => {
	let delta = 0

	if (props.orientation === 'vertical') {
		if (event.key === 'ArrowLeft') {
			delta = -16
		} else if (event.key === 'ArrowRight') {
			delta = 16
		}
	} else if (event.key === 'ArrowUp') {
		delta = 16
	} else if (event.key === 'ArrowDown') {
		delta = -16
	}

	if (delta === 0) {
		return
	}

	event.preventDefault()
	emit('adjust', props.invert ? -delta : delta)
}
</script>

<template>
	<button
		type="button"
		role="separator"
		:aria-label="label"
		:aria-orientation="orientation"
		:class="[
			'relative z-10 bg-default transition-colors hover:bg-primary focus-visible:bg-primary focus-visible:outline-none',
			orientation === 'vertical' ? 'cursor-col-resize' : 'cursor-row-resize',
		]"
		@pointerdown="emit('pointerdown', $event)"
		@keydown="handleKeydown"
	/>
</template>
