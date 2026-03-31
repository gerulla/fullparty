<script setup lang="ts">
import CSidebar from "@/components/Navigation/CSidebar.vue";
import CTopbar from "@/components/Navigation/CTopbar.vue";
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { en, de, fr, ja } from '@nuxt/ui/locale'

const locales = { en, de, fr, ja }
const char1 = '/ft.jpg'

const { t, locale } = useI18n({ useScope: 'global' })

const currentUiLocale = computed(() => {
	return locales[locale.value as keyof typeof locales] ?? locales.en
})
defineProps({
	title: {
		type: String,
		default: "Title"
	}
});
</script>

<template>
	<UApp :locale="currentUiLocale">
		<div class="min-h-screen">
			<UDashboardGroup>
				<CSidebar />

				<UDashboardPanel>
					<CTopbar :title="title" />

					<slot />
				</UDashboardPanel>
			</UDashboardGroup>
		</div>
	</UApp>
</template>

<style scoped>

</style>