<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { usePersistentLocale } from "@/composables/usePersistentLocale";
const char1 = '/ft.jpg'

const { t, locale } = useI18n({ useScope: 'global' })
const { currentUiLocale, localeOptions, updateLocale } = usePersistentLocale();
</script>

<template>
	<UApp :locale="currentUiLocale">
		<div class="w-full h-screen relative flex">
			<div class="w-7/12 h-screen flex items-center">
				<img class="object-cover w-full h-full" :src="char1" alt="">
			</div>
			<div class="w-5/12 h-screen flex justify-center items-center">
				<div class="p-4 mx-24">
					<div class="w-full p-2 mb-4">
						<h1 class="dark:text-white text-6xl font-bold italic">{{ t('meta.title') }}</h1>
						<p class="mx-4 italic text-gray-600 dark:text-gray-300">{{ t('meta.tagline') }}</p>
					</div>
					<slot />


				</div>
			</div>
			<div class="fixed top-0 right-0 p-4 space-x-2">
				<UColorModeSelect variant="ghost" />
				<ULocaleSelect
					variant="ghost"
					v-model="locale"
					:locales="localeOptions"
					@update:model-value="updateLocale"
				/>
			</div>
		</div>
	</UApp>
</template>
