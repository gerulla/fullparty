<script setup>
import {useI18n} from "vue-i18n";

const { t } = useI18n();
</script>

<template>
	<a
		href="/auth/xivauth/redirect"
		class="inline-flex w-full items-center justify-center gap-3 rounded-xl border border-violet-300 bg-violet-50 px-4 py-3 text-sm font-semibold text-violet-900 transition hover:bg-violet-100"
	>
    <span
		class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-violet-600 text-[11px] font-bold leading-none text-white"
	>
        XIV
    </span>

		<span>{{ t('pages.auth.continue_with')}} XIVAuth</span>
	</a>
</template>

<style scoped>

</style>