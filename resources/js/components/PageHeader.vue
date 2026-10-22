<script setup lang="ts">
withDefaults(defineProps<{
	title?: string
	subtitle?: string
	desktopBreakpoint?: "md" | "xl"
}>(), {
	title: "Title",
	subtitle: "Subtitle",
	desktopBreakpoint: "xl",
});

const desktopClasses = {
	md: {
		root: "md:flex-row md:items-start md:justify-between md:text-left",
		content: "md:items-start",
		actions: "md:w-auto md:max-w-full md:shrink md:items-end",
	},
	xl: {
		root: "xl:flex-row xl:items-start xl:justify-between xl:text-left",
		content: "xl:items-start",
		actions: "xl:w-auto xl:max-w-full xl:shrink xl:items-end",
	},
};
</script>

<template>
	<div class="flex max-w-full flex-col items-center gap-4 overflow-x-hidden py-4 text-center" :class="desktopClasses[desktopBreakpoint].root">
		<div class="flex w-full min-w-0 flex-1 flex-col items-center gap-2" :class="desktopClasses[desktopBreakpoint].content">
			<h1 class="break-words wrap-anywhere text-3xl font-black">{{title}}</h1>
			<p class="break-words wrap-anywhere text-muted text-md">{{subtitle}}</p>
		</div>
		<div class="mt-2 flex w-full min-w-0 max-w-full flex-col items-center" :class="desktopClasses[desktopBreakpoint].actions">
			<slot />
		</div>
	</div>
</template>

<style scoped>

</style>
