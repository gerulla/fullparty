<script setup lang="ts">
import { route } from 'ziggy-js'
import {useI18n} from "vue-i18n";
import {de, en, fr, ja} from "@nuxt/ui/locale";
import {computed} from "vue";

const locales = { en, de, fr, ja }
const { t, locale } = useI18n({ useScope: 'global' })

const currentUiLocale = computed(() => {
	return locales[locale.value as keyof typeof locales] ?? locales.en
})

const updateLocale = (value: string) => {
	locale.value = value
}
</script>

<template>
	<UDashboardNavbar title="Inbox">
		<template #leading>
			<UDashboardSidebarCollapse />
		</template>

		<template #trailing>
			<UBadge label="4" variant="subtle" />
		</template>

		<template #right>
			<UColorModeSelect variant="ghost" />
			<ULocaleSelect
				variant="ghost"
				v-model="locale"
				:locales="Object.values(locales)"
				@update:model-value="updateLocale"
			/>
			<UUser
				name="Chad McDick"
				:avatar="{
							  src: 'https://i.pravatar.cc/150?u=john-doe',
							  loading: 'lazy',
							  icon: 'i-lucide-image'
							}"
				:chip="{
							  color: 'success',
							  position: 'top-right'
							}"
			/>
		</template>
	</UDashboardNavbar>
</template>

<style scoped>

</style>