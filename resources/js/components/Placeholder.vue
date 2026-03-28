<script setup lang="ts">
defineProps({
	label: {
		type: String,
		default: '',
	},
	icon: {
		type: String,
		default: '',
	},
	height: {
		type: String,
		default: 'h-48',
	},
	dashed: {
		type: Boolean,
		default: true,
	},
})
</script>

<template>
	<div
		:class="[
      'flex w-full items-center justify-center rounded-xl border bg-muted/30 text-muted',
      height,
      dashed ? 'border-dashed' : 'border-solid'
    ]"
	>
		<div class="flex flex-col items-center gap-2 px-4 text-center">
			<UIcon :name="icon" class="h-5 w-5 opacity-70" />
			<p class="text-sm font-medium">
				{{ label }}
			</p>
			<slot />
		</div>
	</div>
</template>