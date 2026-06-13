<script setup lang="ts">
import NotificationBell from "@/components/Navigation/NotificationBell.vue";
import UserMenu from "@/components/Navigation/UserMenu.vue";
import AppLocaleSelect from "@/components/Navigation/AppLocaleSelect.vue";
import GlobalSearchBox from "@/components/Navigation/GlobalSearchBox.vue";
import TimeDisplayModeToggle from "@/components/Navigation/TimeDisplayModeToggle.vue";
import { usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

const { t } = useI18n({ useScope: 'global' })
const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const isSearchModalOpen = ref(false);

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
			<UDashboardSidebarCollapse />
			<div class="min-w-0">
				<UButton
					color="neutral"
					variant="ghost"
					icon="i-lucide-search"
					size="lg"
					class="sm:hidden"
					:aria-label="t('navigation.topbar.search_bar')"
					@click="isSearchModalOpen = true"
				/>
				<GlobalSearchBox class="hidden w-48 sm:block md:w-56 lg:w-72 xl:w-96" />
			</div>
		</template>

		<template #trailing>
<!--			<UBadge label="4" variant="subtle" />-->
		</template>

		<template #right>
			<TimeDisplayModeToggle v-if="user" />
			<NotificationBell v-if="user" />
			<div class="hidden sm:inline-flex">
				<AppLocaleSelect variant="ghost" />
			</div>
			<div class="inline-flex sm:hidden">
				<AppLocaleSelect variant="ghost" compact />
			</div>
			<UserMenu />
		</template>
	</UDashboardNavbar>

	<UModal
		v-model:open="isSearchModalOpen"
		:title="t('navigation.topbar.search')"
		:ui="{ content: 'max-w-lg', body: 'p-4 sm:p-4' }"
	>
		<template #body>
			<GlobalSearchBox
				autofocus
				dropdown-mode="static"
				@selected="isSearchModalOpen = false"
			/>
		</template>
	</UModal>
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
