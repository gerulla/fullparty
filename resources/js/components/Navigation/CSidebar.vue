<script setup>
import {Link, usePage} from "@inertiajs/vue3";
import Placeholder from "@/components/Placeholder.vue";
import {computed} from "vue";
import {useI18n} from "vue-i18n";
import DevelopmentNotice from "@/components/DevelopmentNotice.vue";

const { t } = useI18n();

const top = [
	{ label: 'Dashboard', href: '/dashboard', icon: 'i-lucide-house' },
	{ label: 'Runs', href: '/dashboard/runs', icon: 'i-lucide-calendar-days' },
]

const account = [
	{ label: 'My Characters', href: '/account/characters', icon: 'i-lucide-user-circle' },
	{ label: 'Applications', href: '/account/applications', icon: 'i-lucide-file-text' },
]
const groups = [
	{ label: 'Groups', href: '/groups', icon: 'i-lucide-shield' },
	{ label: 'Applications', href: '/applications', icon: 'i-lucide-clipboard-list' },
	{ label: 'Members', href: '/applications', icon: 'i-lucide-clipboard-list' },
	{ label: 'Schedule', href: '/applications', icon: 'i-lucide-clipboard-list' },
	{ label: 'Audit', href: '/applications', icon: 'i-lucide-clipboard-list' },
]

const admin = [
	{ label: 'Secret Options', href: '/applications', icon: 'i-lucide-clipboard-list' },
	{ label: 'Configurations', href: '/applications', icon: 'i-lucide-clipboard-list' },
]

const page = usePage()
const logo = "/logo_white.png";
const currentUrl = computed(() => page.url)
</script>

<template>
	<UDashboardSidebar default-size="15"  :ui="{ footer: '',  body: 'px-2' }" class="bg-brand-950">
		<template #header="{ collapsed }">
			<Placeholder class="w-full h-full"/>
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

				<h1 v-if="!collapsed" class="sidebar-separator">ACCOUNT</h1>
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


				<h1 v-if="!collapsed" class="sidebar-separator">GROUP</h1>
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

				<h1 v-if="!collapsed" class="sidebar-separator">ADMIN</h1>
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