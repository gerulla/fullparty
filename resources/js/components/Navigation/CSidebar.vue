<script setup>
import {Link, usePage} from "@inertiajs/vue3";
import Placeholder from "@/components/Placeholder.vue";
import {computed} from "vue";
import {useI18n} from "vue-i18n";

const { t } = useI18n();

const top = [
	{ label: 'Profile', href: '/dashboard', icon: 'i-lucide-layout-dashboard' },
]

const account = [
	{ label: 'My Characters', href: '/characters', icon: 'i-lucide-users' },
	{ label: 'Runs', href: '/runs', icon: 'i-lucide-calendar-range' },
	{ label: 'Registrations', href: '/registrations', icon: 'i-lucide-form' },
	{ label: 'Settings', href: '/settings', icon: 'i-lucide-settings' }
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
	<UDashboardSidebar collapsible default-size="20"  :ui="{ footer: 'px-0', body: 'px-0' }" class=" ">
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
			<div class="flex flex-col w-full h-full">
				<Link
					:href="route('logout')"
					method="post"
					as="button"
					class="sidebar-link link-default"
				>
					<UIcon name="i-lucide-log-out" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
					<span v-if="!collapsed">{{ t('pages.verify_email.logout_button') }}</span>

				</Link>
			</div>
		</template>
	</UDashboardSidebar>
</template>

<style scoped>
@reference "../../../css/app.css";

.sidebar-link-icon {
	@apply h-4 w-4;
}
.sidebar-link-icon-large {
	@apply h-8 w-8;
}
.sidebar-line-separator {
	@apply h-px w-full my-2 bg-neutral-300 dark:bg-neutral-600;
}
.sidebar-separator {
	@apply mt-6 mb-2 px-5 text-sm font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500;
}
.link-highlighted {
	@apply dark:bg-brand-600/40 text-neutral-200 bg-brand;
}
.link-default {
	@apply dark:text-neutral-200 hover:bg-brand hover:text-white dark:hover:bg-brand-600;
}
.sidebar-link {
	@apply flex items-center gap-2 py-2 px-5 text-lg transition;
}
</style>