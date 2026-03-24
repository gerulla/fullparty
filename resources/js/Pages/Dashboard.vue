<script setup>
import { route } from 'ziggy-js'
import {Link} from "@inertiajs/vue3";
import {useI18n} from "vue-i18n";
const { t } = useI18n()
</script>

<template>
<div>
	<h1>Dashboard babybaby</h1>
	<Link
		:href="route('logout')"
		method="post"
		as="button"
		class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 px-5 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
	>
		{{ t('pages.verify_email.logout_button') }}
	</Link>
</div>
</template>

<style scoped>

</style>