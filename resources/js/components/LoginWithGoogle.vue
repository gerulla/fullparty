<script setup lang="ts">
import {useI18n} from "vue-i18n";
import { route } from "ziggy-js";

const { t } = useI18n();
defineProps<{ href?: string }>();
</script>

<template>
	<a
		:href="href ?? route('google.redirect')"
		class="inline-flex w-full items-center justify-center gap-3 rounded-xl border border-[#747775] bg-white px-4 py-3 text-sm font-medium text-[#1f1f1f] transition hover:bg-[#f2f2f2] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:border-[#8e918f] dark:bg-[#131314] dark:text-[#e3e3e3] dark:hover:bg-[#242428]"
	>
		<!-- Official asset: https://developers.google.com/static/identity/images/g-logo.png -->
		<img :src="'/logos/providers/google.png'" alt="" width="20" height="20" class="h-5 w-5 shrink-0 object-contain">

		<span>{{ t('auth.continue_with')}} Google</span>
	</a>
</template>

<style scoped>

</style>
