<script setup lang="ts">
import CSidebar from "@/components/Navigation/CSidebar.vue";
import CTopbar from "@/components/Navigation/CTopbar.vue";
import AccountFlags from "@/components/Navigation/AccountFlags.vue";
import DashboardFooter from "@/components/DashboardFooter.vue";
import GroupNavigation from "@/components/Groups/GroupNavigation.vue";
import SystemBanner from "@/components/SystemBanner.vue";
import WelcomeOnboardingModal from "@/components/Home/WelcomeOnboardingModal.vue";
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useXivAuthConflictToast } from '@/composables/useXivAuthConflictToast'
import { usePersistentLocale } from "@/composables/usePersistentLocale";
const page = usePage()
useXivAuthConflictToast()
const { currentUiLocale } = usePersistentLocale();

const currentGroup = computed(() => page.props.group ?? null)
const systemBanner = computed(() => page.props.system_banner ?? null)
const isResourceManagementPage = computed(() => page.component === 'Dashboard/Groups/Resources/Manage')
const showGroupNavigation = computed(() => {
	if (currentGroup.value === null) {
		return false
	}

	return page.url.includes('/dashboard')
})

defineProps({
	title: {
		type: String,
		default: "Title"
	}
});
</script>

<template>
	<UApp :locale="currentUiLocale" class="">
		<div class="min-h-screen bg-linear-to-br from-brand-900 via-neutral-950 via-15% to-neutral-950 to-90%" >
			<UDashboardGroup storage="local" storage-key="fullparty-dashboard">
				<CSidebar />

				<UDashboardPanel :ui="{ body: isResourceManagementPage ? 'p-0 sm:p-0 lg:min-h-0 lg:overflow-hidden' : 'p-2 sm:p-3 lg:p-4 xl:p-6' }">
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
						<div class="flex min-h-full flex-col" :class="{ 'lg:h-full lg:min-h-0': isResourceManagementPage }">
							<div class="flex-1" :class="{ 'lg:flex lg:min-h-0 lg:flex-col': isResourceManagementPage }">
								<slot />
							</div>

							<DashboardFooter
								class="mt-8"
								:class="{ 'lg:mt-4': isResourceManagementPage }"
								:has-bottom-navigation="showGroupNavigation"
							/>
						</div>
					</template>
				</UDashboardPanel>
			</UDashboardGroup>
			<WelcomeOnboardingModal />
			<AccountFlags v-if="page.props.auth?.user" />
		</div>
	</UApp>
</template>

<style scoped>

</style>
