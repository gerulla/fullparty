<script setup lang="ts">
defineProps<{
	group: {
		name: string
	} | null | undefined
	textColor?: string
}>();
</script>

<template>
	<UBadge
		v-if="group"
		class="max-w-full"
		color="neutral"
		variant="soft"
		size="sm"
		icon="i-lucide-shield"
	>
		<span class="truncate" :style="{ color: textColor }">{{ group.name }}</span>
	</UBadge>
</template>
