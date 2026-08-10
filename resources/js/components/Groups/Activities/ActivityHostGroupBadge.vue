<script setup lang="ts">
defineProps<{
	group: {
		name: string
	} | null | undefined
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
		<span class="truncate">{{ group.name }}</span>
	</UBadge>
</template>