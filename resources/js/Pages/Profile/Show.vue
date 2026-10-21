<script setup lang="ts">
import { Head } from "@inertiajs/vue3"
import { useI18n } from "vue-i18n"
import HomepageActivityOverview from "@/components/Home/HomepageActivityOverview.vue"
import HomepageBanner from "@/components/Home/HomepageBanner.vue"
import type { DashboardPageProps } from "../../Types/Dashboard"

const props = defineProps<DashboardPageProps>()
const { t } = useI18n()
</script>

<template>
	<div class="w-full">
		<Head :title="t('navigation.topbar.menu.profile')" />
		<HomepageBanner
			:profile="props.profile"
			:home-profile-options="props.homeProfileOptions"
			:home-banner="props.homeBanner"
			:home-banner-details="props.homeBannerDetails"
			:home-account-completion="props.homeAccountCompletion"
		/>
		<HomepageActivityOverview :overview="props.homeActivityOverview" />
	</div>
</template>
