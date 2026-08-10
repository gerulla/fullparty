<script setup lang="ts">
import CSidebar from "@/components/Navigation/CSidebar.vue";
import CTopbar from "@/components/Navigation/CTopbar.vue";
import ViewportBreakpointIndicator from "@/components/Navigation/ViewportBreakpointIndicator.vue";
import DashboardFooter from "@/components/DashboardFooter.vue";
import GroupNavigation from "@/components/Groups/GroupNavigation.vue";
import SystemBanner from "@/components/SystemBanner.vue";
import WelcomeOnboardingModal from "@/components/Home/WelcomeOnboardingModal.vue";
import { usePage } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@nuxt/ui/composables'
import { usePersistentLocale } from "@/composables/usePersistentLocale";
const page = usePage()
const toast = useToast()
const { t } = useI18n()
const { currentUiLocale } = usePersistentLocale();
const showBreakpointIndicator = import.meta.env.DEV;

const currentGroup = computed(() => page.props.group ?? null)
const systemBanner = computed(() => page.props.system_banner ?? null)
const activityOverviewComponents = [
	'Groups/Activities/Overview',
	'Groups/Activities/NonApplicationOverview',
]
const isGroupActivityOverviewPage = computed(() => activityOverviewComponents.includes(page.component))
const showGroupNavigation = computed(() => {
	if (currentGroup.value === null) {
		return false
	}

	return page.url.includes('/dashboard')
		|| (isGroupActivityOverviewPage.value && Boolean(currentGroup.value.permissions?.can_view_members))
})
const xivAuthCharacterSyncConflicts = computed(() => {
	const conflicts = page.props.flash?.data?.xivauth_character_sync?.conflicts

	return Array.isArray(conflicts) ? conflicts : []
})

watch(
	xivAuthCharacterSyncConflicts,
	(conflicts) => {
		if (conflicts.length === 0) {
			return
		}

		const characterNames = conflicts
			.map((conflict) => conflict?.name)
			.filter(Boolean)
			.join(', ')

		toast.add({
			title: t('characters.xivauth.sync.conflict_title'),
			description: t(
				conflicts.length === 1
					? 'characters.xivauth.sync.conflict_description_one'
					: 'characters.xivauth.sync.conflict_description_many',
				{ characters: characterNames },
			),
			color: 'warning',
			icon: 'i-lucide-triangle-alert',
		})
	},
	{ immediate: true },
)

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

				<UDashboardPanel :ui="{ body: 'p-2 sm:p-3 lg:p-4 xl:p-6' }">
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

							<DashboardFooter
								class="mt-8"
								:has-bottom-navigation="showGroupNavigation"
							/>
						</div>
					</template>
				</UDashboardPanel>
			</UDashboardGroup>
			<WelcomeOnboardingModal />
			<ViewportBreakpointIndicator v-if="showBreakpointIndicator" />
		</div>
	</UApp>
</template>

<style scoped>

</style>
