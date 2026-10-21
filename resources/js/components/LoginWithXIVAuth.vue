<script setup lang="ts">
import {useI18n} from "vue-i18n";
import { route } from "ziggy-js";

const { t } = useI18n();
defineProps<{ href?: string }>();
</script>

<template>
	<a
		:href="href ?? route('xivauth.redirect')"
		class="xivauth-login inline-flex w-full items-center justify-center gap-3 rounded-xl border px-4 py-3 text-sm font-semibold text-default transition focus-visible:outline-2 focus-visible:outline-offset-2"
	>
		<img :src="'/logos/providers/xivauth-key-45.svg'" class="size-6 shrink-0" alt="" width="24" height="24" />
		<span>{{ t('auth.continue_with') }} XIVAuth</span>
	</a>
</template>

<style scoped>
.xivauth-login {
    --xivauth-brand: #00a8e8;
    border-color: var(--xivauth-brand);
    background-color: var(--ui-bg);
    outline-color: var(--xivauth-brand);
}

.xivauth-login:hover {
    background-color: color-mix(in oklab, var(--xivauth-brand) 8%, var(--ui-bg-elevated));
}
</style>
