<script setup lang="ts">
import { computed } from "vue";
import { groupDiscoveryBadgeStyle } from "@/utils/groupDiscoveryBadgeStyles";

const props = withDefaults(defineProps<{
	label: string
	color?: string | null
}>(), {
	color: null,
});

const badgeStyle = computed(() => {
	const style = groupDiscoveryBadgeStyle(props.color);
	if (!style) return undefined;

	return {
		...style,
		"--discovery-badge-accent": props.color,
		"--discovery-badge-dark-background": style.backgroundColor,
		backgroundColor: "var(--discovery-badge-background)",
		color: "var(--discovery-badge-foreground)",
	};
});
const fallbackClass = computed(() => (
	props.color
		? "border-transparent bg-transparent"
		: "border-neutral-200 bg-neutral-100 text-neutral-700 dark:border-white/10 dark:bg-white/5 dark:text-white/85"
));
</script>

<template>
	<span
		class="discovery-badge inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap"
		:class="fallbackClass"
		:style="badgeStyle"
	>
		{{ label }}
	</span>
</template>

<style scoped>
.discovery-badge {
	--discovery-badge-background: color-mix(in srgb, var(--discovery-badge-accent) 10%, var(--ui-bg-elevation-1));
	--discovery-badge-foreground: color-mix(in srgb, var(--discovery-badge-accent) 40%, var(--ui-text-highlighted));
}

.dark .discovery-badge {
	--discovery-badge-background: var(--discovery-badge-dark-background);
	--discovery-badge-foreground: #f8fafc;
}
</style>
