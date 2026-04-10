<script setup>
import {Link, usePage} from "@inertiajs/vue3";
import Placeholder from "@/components/Placeholder.vue";
import {computed} from "vue";
import {useI18n} from "vue-i18n";
import DevelopmentNotice from "@/components/DevelopmentNotice.vue";

const { t } = useI18n();

const top = computed(() => [
	{ label: t('navigation.sidebar.dashboard'), href: '/dashboard', icon: 'i-lucide-house' },
	{ label: t('navigation.sidebar.runs'), href: '/dashboard/runs', icon: 'i-lucide-calendar-days' },
])

const account = computed(() => [
	{ label: t('navigation.sidebar.characters'), href: '/account/characters', icon: 'i-lucide-user-circle' },
	{ label: t('navigation.sidebar.applications'), href: '/account/applications', icon: 'i-lucide-file-text' },
])

const groups = computed(() => [
	{ label: t('navigation.sidebar.groups'), href: '/groups', icon: 'i-lucide-shield' },
	{ label: t('navigation.sidebar.applications'), href: '/applications', icon: 'i-lucide-clipboard-list' },
	{ label: t('navigation.sidebar.members'), href: '/applications', icon: 'i-lucide-clipboard-list' },
	{ label: t('navigation.sidebar.schedule'), href: '/applications', icon: 'i-lucide-clipboard-list' },
	{ label: t('navigation.sidebar.audit'), href: '/applications', icon: 'i-lucide-clipboard-list' },
])

const admin = computed(() => [
	{ label: t('navigation.sidebar.character_definitions'), href: '/admin/character-data', icon: 'i-lucide-user-pen' },
	{ label: t('navigation.sidebar.run_definitions'), href: '/admin/runs/definitions', icon: 'i-lucide-file-pen' },
])

const page = usePage()
const full_logo = "/logos/full.png";
const compact_logo = "/logos/compact.png";
const currentUrl = computed(() => page.url)
</script>

<template>
	<UDashboardSidebar :default-size="15"  :ui="{ footer: '',  body: 'px-2' }" class="bg-brand-950">
		<template #header="{ collapsed }">
			<div v-if="!collapsed" class="w-full h-full p-4">
				<img :src="full_logo" class="w-full h-auto" alt="FullParty Logo">
			</div>
			<img v-else :src="compact_logo" class="w-full h-auto" alt="FullParty Logo">
		</template>

		<template #default="{ collapsed }">
			<div class="mt-4 flex flex-col w-full h-full ">
				<Link
					v-for="item in top"
					:key="item.href"
					:href="item.href"
					class="sidebar-link"
					:class="currentUrl.startsWith(item.href) ? 'link-highlighted': 'link-default'"
				>
					<UIcon :name="item.icon" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
					<span v-if="!collapsed">{{ item.label }}</span>
				</Link>

				<h1 v-if="!collapsed" class="sidebar-separator">{{t('navigation.sidebar.account')}}</h1>
				<div v-else class="sidebar-line-separator"></div>

				<Link
					v-for="item in account"
					:key="item.href"
					:href="item.href"
					class="sidebar-link"
					:class="currentUrl.startsWith(item.href) ? 'link-highlighted': 'link-default'"
				>
					<UIcon :name="item.icon" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
					<span v-if="!collapsed">{{ item.label }}</span>
				</Link>


				<h1 v-if="!collapsed" class="sidebar-separator">{{t('navigation.sidebar.groups')}}</h1>
				<div v-else class="sidebar-line-separator"></div>

				<Link
					v-for="item in groups"
					:key="item.href"
					:href="item.href"
					class="sidebar-link"
					:class="currentUrl.startsWith(item.href) ? 'link-highlighted': 'link-default'"
				>
					<UIcon :name="item.icon" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
					<span v-if="!collapsed">{{ item.label }}</span>
				</Link>

				<h1 v-if="!collapsed" class="sidebar-separator">{{t('navigation.sidebar.admin')}}</h1>
				<div v-else class="sidebar-line-separator"></div>

				<Link
					v-for="item in admin"
					:key="item.href"
					:href="item.href"
					class="sidebar-link"
					:class="currentUrl.startsWith(item.href) ? 'link-highlighted': 'link-default'"
				>
					<UIcon :name="item.icon" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
					<span v-if="!collapsed">{{ item.label }}</span>
				</Link>
			</div>
		</template>

		<template #footer="{ collapsed }">
			<DevelopmentNotice />
		</template>
	</UDashboardSidebar>
</template>

<style scoped>
@reference "../../../css/app.css";

.sidebar-link-icon {
	@apply h-5 w-5;
}
.sidebar-link-icon-large {
	@apply h-8 w-8;
}
.sidebar-line-separator {
	@apply h-px w-full my-2 bg-brand-300;
}
.sidebar-separator {
	@apply mt-6 mb-2 px-5 text-sm font-semibold uppercase tracking-wider text-brand-300/80 ;
}
.link-highlighted {
	@apply text-neutral-200 bg-brand rounded-xs;
}
.link-default {
	@apply text-brand-100/80 hover:bg-brand hover:text-white rounded-xs;
}
.sidebar-link {
	@apply flex items-center gap-2 py-2 px-5  transition;
}
</style>
