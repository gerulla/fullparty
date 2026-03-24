<script setup>
import {useI18n} from "vue-i18n";

const { t } = useI18n();
</script>

<template>
	<a
		href="/auth/discord/redirect"
		class="inline-flex w-full items-center justify-center gap-3 rounded-xl border border-indigo-500 bg-indigo-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-indigo-600"
	>
		<svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
			<path
				d="M20.317 4.369A19.791 19.791 0 0 0 15.885 3c-.191.328-.403.769-.552 1.116a18.27 18.27 0 0 0-5.29 0A11.64 11.64 0 0 0 9.49 3a19.736 19.736 0 0 0-4.434 1.37C2.249 8.56 1.52 12.647 1.885 16.676a19.9 19.9 0 0 0 5.437 2.724c.438-.6.828-1.235 1.165-1.903c-.627-.236-1.227-.53-1.794-.876c.15-.11.297-.223.438-.34c3.462 1.62 7.22 1.62 10.64 0c.143.118.29.23.438.34a11.77 11.77 0 0 1-1.797.877c.338.667.728 1.302 1.167 1.902a19.84 19.84 0 0 0 5.438-2.724c.43-4.67-.734-8.72-3.042-12.307ZM9.75 14.213c-1.036 0-1.886-.95-1.886-2.116c0-1.167.832-2.117 1.886-2.117c1.063 0 1.904.959 1.886 2.117c0 1.167-.832 2.116-1.886 2.116Zm4.5 0c-1.036 0-1.886-.95-1.886-2.116c0-1.167.832-2.117 1.886-2.117c1.063 0 1.904.959 1.886 2.117c0 1.167-.823 2.116-1.886 2.116Z"
			/>
		</svg>

		<span>{{ t('pages.auth.continue_with')}} Discord</span>
	</a>
</template>

<style scoped>

</style>