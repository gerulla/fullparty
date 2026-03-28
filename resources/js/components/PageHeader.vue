<script setup lang="ts">
defineProps({
	title: {
		type: String,
		default: "Title",
	},
	subtitle: {
		type: String,
		default: "Subtitle",
	}
});
</script>

<template>
	<div class="w-full flex flex-col items-start py-4 gap-2">
		<h1 class="text-3xl font-black">{{title}}</h1>
		<p class="text-muted text-md">{{subtitle}}</p>
	</div>
</template>

<style scoped>

</style>