<script setup lang="ts">
import CSidebar from "@/components/Navigation/CSidebar.vue";
import CTopbar from "@/components/Navigation/CTopbar.vue";
import GroupNavigation from "@/components/Groups/GroupNavigation.vue";
import { usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { usePersistentLocale } from "@/composables/usePersistentLocale";
const page = usePage()
const { currentUiLocale } = usePersistentLocale();

const currentGroup = computed(() => page.props.group ?? null)
const showGroupNavigation = computed(() => {
	return page.url.includes('/dashboard') && currentGroup.value !== null
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

				<UDashboardPanel :ui="{ body: 'bg-neutral-100 dark:bg-neutral-900' }">
					<template #header>
						<CTopbar :title="title" />
						<GroupNavigation
							v-if="showGroupNavigation"
							:group="currentGroup"
						/>
					</template>

					<template #body>
						<slot />
					</template>
				</UDashboardPanel>
			</UDashboardGroup>
		</div>
	</UApp>
</template>

<style scoped>

</style>
