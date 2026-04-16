<script setup lang="ts">
import { usePersistentLocale } from "@/composables/usePersistentLocale";

const { currentUiLocale, localeOptions, updateLocale } = usePersistentLocale();
</script>

<template>
	<UApp :locale="currentUiLocale">
		<div class="min-h-screen bg-neutral-100 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-50">
			<div class="fixed right-0 top-0 z-10 flex items-center gap-2 p-4">
				<UColorModeSelect variant="ghost" />
				<ULocaleSelect
					variant="ghost"
					:locales="localeOptions"
					:model-value="currentUiLocale.code"
					@update:model-value="updateLocale"
				/>
			</div>

			<div class="relative flex min-h-screen items-center justify-center overflow-hidden px-6 py-16">
				<div class="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(255,255,255,0.9),_rgba(245,245,245,0.92)_35%,_rgba(229,229,229,0.95)_100%)] dark:bg-[radial-gradient(circle_at_top,_rgba(64,64,64,0.35),_rgba(23,23,23,0.96)_45%,_rgba(10,10,10,1)_100%)]" />
				<div class="absolute -left-24 top-20 h-72 w-72 rounded-full bg-amber-300/30 blur-3xl dark:bg-amber-400/10" />
				<div class="absolute -right-24 bottom-10 h-80 w-80 rounded-full bg-sky-300/30 blur-3xl dark:bg-sky-400/10" />

				<div class="relative w-full max-w-3xl">
					<slot />
				</div>
			</div>
		</div>
	</UApp>
</template>
