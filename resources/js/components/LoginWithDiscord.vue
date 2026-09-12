<script setup lang="ts">
import {useI18n} from "vue-i18n";
import { route } from "ziggy-js";

const { t } = useI18n();
defineProps<{ href?: string }>();
</script>

<template>
	<a
		:href="href ?? route('discord.redirect')"
		class="inline-flex w-full items-center justify-center gap-3 rounded-xl border border-indigo-500 bg-indigo-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-indigo-600"
	>
		<UIcon name="ic:baseline-discord" class="h-5 w-5" />

		<span>{{ t('auth.continue_with')}} Discord</span>
	</a>
</template>

<style scoped>

</style>
