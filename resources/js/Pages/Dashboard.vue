<script setup lang="ts">

import { route } from 'ziggy-js'
import {Link} from "@inertiajs/vue3";
import {useI18n} from "vue-i18n";
const { t } = useI18n()
</script>

<template>
	<div class="px-4 pt-4 w-full h-full flex flex-col gap-4">
		<div class="w-full h-full flex flex-row gap-4">
			<div class="w-full bg-neutral-100 dark:bg-neutral-800 rounded-lg"></div>
			<div class="w-full bg-neutral-100 dark:bg-neutral-800 rounded-lg"></div>
			<div class="w-full bg-neutral-100 dark:bg-neutral-800 rounded-lg"></div>
		</div>
		<div class="w-full h-full flex flex-row gap-4">
			<div class="w-full bg-neutral-100 dark:bg-neutral-800 rounded-lg"></div>
		</div>
		<div class="w-full h-full flex flex-row gap-4">
			<div class="w-full bg-neutral-100 dark:bg-neutral-800 rounded-lg"></div>
			<div class="w-full bg-neutral-100 dark:bg-neutral-800 rounded-lg"></div>
		</div>
	</div>
</template>

<style scoped>

</style>