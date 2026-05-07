<script setup lang="ts">
import CSidebar from "@/components/Navigation/CSidebar.vue";
import CTopbar from "@/components/Navigation/CTopbar.vue";
import DashboardFooter from "@/components/DashboardFooter.vue";
import GroupNavigation from "@/components/Groups/GroupNavigation.vue";
import SystemBanner from "@/components/SystemBanner.vue";
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { usePersistentLocale } from "@/composables/usePersistentLocale";
const page = usePage()
const { currentUiLocale } = usePersistentLocale();

const currentGroup = computed(() => page.props.group ?? null)
const systemBanner = computed(() => page.props.system_banner ?? null)
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
						<SystemBanner
							v-if="systemBanner"
							:banner="systemBanner"
						/>
						<CTopbar :title="title" />
						<GroupNavigation
							v-if="showGroupNavigation"
							:group="currentGroup"
						/>
					</template>

					<template #body>
						<div class="flex min-h-full flex-col">
							<div class="flex-1">
								<slot />
							</div>

							<DashboardFooter class="mt-8" />
						</div>
					</template>
				</UDashboardPanel>
			</UDashboardGroup>
		</div>
	</UApp>
</template>

<style scoped>

</style>
