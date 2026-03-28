<script setup lang="ts">
import { route } from 'ziggy-js'
import { useI18n } from "vue-i18n"
import { de, en, fr, ja } from "@nuxt/ui/locale"
import { computed, ref } from "vue"
import NotificationButton from "@/components/NotificationButton.vue";
import UserMenu from "@/components/Navigation/UserMenu.vue";

const locales = { en, de, fr, ja }
const { t, locale } = useI18n({ useScope: 'global' })

const currentUiLocale = computed(() => {
	return locales[locale.value as keyof typeof locales] ?? locales.en
})

const updateLocale = (value: string) => {
	locale.value = value
}

defineProps({
	title: {
		type: String,
		default: "Title"
	}
});
</script>

<template>
	<UDashboardNavbar>
		<template #leading>
<!--			<UDashboardSidebarCollapse />-->
			<UInput placeholder="Search raids, players, or events..." :ui="{base: 'rounded-sm placeholder:text-neutral-500'}" leading-icon="i-lucide-search" size="lg" class="min-w-96"/>
		</template>

		<template #trailing>
<!--			<UBadge label="4" variant="subtle" />-->
		</template>

		<template #right>
			<NotificationButton />
			<UColorModeButton />
			<ULocaleSelect
				variant="ghost"
				v-model="locale"
				:locales="Object.values(locales)"
				@update:model-value="updateLocale"
			/>
			<UserMenu />
		</template>
	</UDashboardNavbar>
</template>

<style scoped>
/* Optional: Add custom scrollbar styling for the notifications panel */
.max-h-96::-webkit-scrollbar {
	width: 6px;
}

.max-h-96::-webkit-scrollbar-track {
	background: transparent;
}

.max-h-96::-webkit-scrollbar-thumb {
	background: rgba(156, 163, 175, 0.3);
	border-radius: 3px;
}

.max-h-96::-webkit-scrollbar-thumb:hover {
	background: rgba(156, 163, 175, 0.5);
}
</style>
