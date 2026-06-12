<script setup>
import {Link, usePage} from "@inertiajs/vue3";
import {computed, ref, watch} from "vue";
import {useI18n} from "vue-i18n";
import DevelopmentNotice from "@/components/DevelopmentNotice.vue";

const { t } = useI18n();
const page = usePage()
const currentLocale = computed(() => String(page.props.locale?.current ?? 'en'))
const isAuthenticated = computed(() => Boolean(page.props.auth?.user))
const localizedRoute = (...args) => {
	currentLocale.value

	return route(...args)
}
const loginHref = computed(() => localizedRoute('login'));
const authLink = (href, icon, label, activePatterns) => ({
	label,
	icon,
	href: isAuthenticated.value ? href : loginHref.value,
	activePatterns,
})
const isRouteActive = (patterns) => {
	const routeMatcher = route()

	return patterns.some((pattern) => routeMatcher.current(pattern))
}

const top = computed(() => [
	authLink(localizedRoute('dashboard'), 'i-lucide-house', t('navigation.sidebar.dashboard'), ['dashboard']),
])

const runs = computed(() => [
	authLink(localizedRoute('dashboard.runs.index'), 'i-lucide-search', t('navigation.sidebar.find_runs'), ['dashboard.runs.*']),
	authLink(localizedRoute('account.applications'), 'i-lucide-file-text', t('navigation.sidebar.applications'), ['account.applications*']),
])

const account = computed(() => [
	authLink(localizedRoute('account.characters'), 'i-lucide-user-circle', t('navigation.sidebar.characters'), ['account.characters*']),
])

const groups = computed(() => [
	{ label: t('navigation.sidebar.groups'), href: localizedRoute('groups.index'), icon: 'i-lucide-shield', activePatterns: ['groups.index'] },
	{ label: t('navigation.sidebar.my_requests'), href: localizedRoute('groups.requests.index'), icon: 'i-lucide-inbox', activePatterns: ['groups.requests.*'] },
])

const admin = computed(() => [
	{ label: t('navigation.sidebar.character_definitions'), href: localizedRoute('admin.character-data'), icon: 'i-lucide-user-pen', activePatterns: ['admin.character-data'] },
	{ label: t('navigation.sidebar.admin_audit_log'), href: localizedRoute('admin.audit-log'), icon: 'i-lucide-scroll-text', activePatterns: ['admin.audit-log'] },
	{ label: t('navigation.sidebar.system_notifications'), href: localizedRoute('admin.system-notifications.index'), icon: 'i-lucide-megaphone', activePatterns: ['admin.system-notifications.*'] },
	{ label: t('navigation.sidebar.featured_groups'), href: localizedRoute('admin.featured-groups.index'), icon: 'i-lucide-sparkles', activePatterns: ['admin.featured-groups.*'] },
	{ label: t('navigation.sidebar.integrations'), href: localizedRoute('admin.integrations.index'), icon: 'i-lucide-plug-zap', activePatterns: ['admin.integrations.*'] },
	{ label: t('navigation.sidebar.activity_types'), href: localizedRoute('admin.activity-types.index'), icon: 'i-lucide-file-pen', activePatterns: ['admin.activity-types.*'] },
	{ label: t('navigation.sidebar.pulse'), href: '/pulse', icon: 'i-lucide-activity', external: true },
])

const full_logo = "/logos/full.png";
const compact_logo = "/logos/compact.png";
const currentUrl = computed(() => page.url)
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin))
const normalizeQuickLinks = (items) => Array.isArray(items) ? items : []
const groupQuickLinks = computed(() => {
	const quickLinks = page.props.navigation?.group_quick_links ?? {}

	return {
		my: normalizeQuickLinks(quickLinks.my),
		joined: normalizeQuickLinks(quickLinks.joined),
	}
})
const groupDrawerOpen = ref({
	my: false,
	joined: false,
})
const sidebarOpen = ref(false)
const appVersion = computed(() => {
	const version = page.props.app_version?.version

	return typeof version === 'string' && version.trim() !== '' ? version.trim() : 'dev'
})
const appVersionTitle = computed(() => {
	const details = [appVersion.value]
	const commit = page.props.app_version?.commit
	const deployedAt = page.props.app_version?.deployed_at

	if (typeof commit === 'string' && commit.trim() !== '') {
		details.push(commit.trim())
	}

	if (typeof deployedAt === 'string' && deployedAt.trim() !== '') {
		details.push(deployedAt.trim())
	}

	return details.join(' • ')
})

const groupQuickLinkSections = computed(() => [
	{
		key: 'my',
		label: t('navigation.sidebar.my_groups'),
		icon: 'i-lucide-crown',
		items: groupQuickLinks.value.my,
	},
	{
		key: 'joined',
		label: t('navigation.sidebar.joined_groups'),
		icon: 'i-lucide-users',
		items: groupQuickLinks.value.joined,
	},
].filter((section) => section.items.length > 0))

const syncActiveGroupDrawer = () => {
	for (const section of groupQuickLinkSections.value) {
		if (section.items.some((group) => currentUrl.value.startsWith(group.href))) {
			groupDrawerOpen.value[section.key] = true
		}
	}
}

watch([currentUrl, groupQuickLinkSections], () => {
	syncActiveGroupDrawer()
}, { immediate: true })

const closeSidebarMenu = () => {
	sidebarOpen.value = false
}
</script>

<template>
	<UDashboardSidebar v-model:open="sidebarOpen" :default-size="15"  :ui="{ footer: '',  body: 'px-4' }" class="max-w-96 border-0 lg:max-2xl:!w-1/4">
		<template #header="{ collapsed }">
			<div v-if="!collapsed" class="w-full h-full mt-8">
				<img :src="full_logo" class="h-full w-auto mx-auto " alt="FullParty Logo">
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
					:class="isRouteActive(item.activePatterns) ? 'link-highlighted': 'link-default'"
					@click="closeSidebarMenu"
				>
					<UIcon :name="item.icon" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
					<span v-if="!collapsed">{{ item.label }}</span>
				</Link>

				<h1 v-if="!collapsed" class="sidebar-separator">{{t('navigation.sidebar.runs')}}</h1>
				<div v-else class="sidebar-line-separator"></div>

				<Link
					v-for="item in runs"
					:key="item.href"
					:href="item.href"
					class="sidebar-link"
					:class="isRouteActive(item.activePatterns) ? 'link-highlighted': 'link-default'"
					@click="closeSidebarMenu"
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
					:class="isRouteActive(item.activePatterns) ? 'link-highlighted': 'link-default'"
					@click="closeSidebarMenu"
				>
					<UIcon :name="item.icon" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
					<span v-if="!collapsed">{{ item.label }}</span>
				</Link>


				<h1 v-if="!collapsed" class="sidebar-separator">{{t('navigation.sidebar.groups')}}</h1>
				<div v-else class="sidebar-line-separator"></div>

				<component
					:is="item.native ? 'a' : Link"
					v-for="item in groups"
					:key="item.href"
					:href="item.href"
					class="sidebar-link"
					:class="item.activePatterns.length > 0 && isRouteActive(item.activePatterns) ? 'link-highlighted': 'link-default'"
					@click="closeSidebarMenu"
				>
					<UIcon :name="item.icon" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
					<span v-if="!collapsed">{{ item.label }}</span>
				</component>

				<div v-if="!collapsed" class="mt-2 flex flex-col gap-2">
					<div
						v-for="section in groupQuickLinkSections"
						:key="section.key"
						class="flex flex-col"
					>
						<button
							type="button"
							class="sidebar-link w-full justify-between link-default"
							@click="groupDrawerOpen[section.key] = !groupDrawerOpen[section.key]"
						>
							<div class="flex min-w-0 items-center gap-2">
								<UIcon :name="section.icon" class="sidebar-link-icon" />
								<span class="truncate">{{ section.label }}</span>
							</div>
							<UIcon
								name="i-lucide-chevron-down"
								class="h-4 w-4 shrink-0 transition-transform"
								:class="groupDrawerOpen[section.key] ? 'rotate-180' : ''"
							/>
						</button>

						<div v-if="groupDrawerOpen[section.key]" class="mt-1 flex flex-col gap-1">
							<div class="mt-1 flex flex-col gap-1">
								<Link
									v-for="group in section.items"
									:key="group.id"
									:href="group.href"
									class="sidebar-sublink"
									:class="currentUrl.startsWith(group.href) ? 'sublink-highlighted' : 'sublink-default'"
									@click="closeSidebarMenu"
								>
									<span class="truncate">{{ group.name }}</span>
								</Link>
							</div>
						</div>
					</div>
				</div>

				<template v-if="isAdmin">
					<h1 v-if="!collapsed" class="sidebar-separator">{{t('navigation.sidebar.admin')}}</h1>
					<div v-else class="sidebar-line-separator"></div>

					<component
						:is="item.external ? 'a' : Link"
						v-for="item in admin"
						:key="item.href"
						:href="item.href"
						class="sidebar-link"
						:class="!item.external && isRouteActive(item.activePatterns) ? 'link-highlighted': 'link-default'"
						:target="item.external ? '_blank' : undefined"
						:rel="item.external ? 'noopener noreferrer' : undefined"
						@click="closeSidebarMenu"
					>
						<UIcon :name="item.icon" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
						<span v-if="!collapsed" class="flex min-w-0 items-center gap-2">
							<span class="truncate">{{ item.label }}</span>
							<UIcon v-if="item.external" name="i-lucide-arrow-up-right" class="h-3.5 w-3.5 shrink-0 text-brand-200/70" />
						</span>
					</component>
				</template>
			</div>
		</template>

		<template #footer="{ collapsed }">
			<div class="flex flex-col gap-4 px-4 pb-4">
				<DevelopmentNotice v-if="!collapsed" />
				<div
					class="flex items-center gap-2 text-xs text-brand-100/45"
					:class="collapsed ? 'justify-center' : 'justify-between'"
					:title="appVersionTitle"
				>
					<span v-if="!collapsed" class="uppercase tracking-wider">FullParty</span>
					<span class="font-mono">{{ appVersion }}</span>
				</div>
			</div>
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
	@apply text-neutral-200 rounded-xs border-l-4 border-brand-500 bg-linear-to-r from-brand-800 via-brand-800/30 to-brand-800/10;
}
.link-default {
	@apply text-brand-100/80 hover:text-white rounded-xs hover:bg-linear-to-r from-brand-800 via-brand-800/30 to-brand-800/10;
}
.sublink-highlighted {
	@apply text-neutral-100 border-l-4 border-brand-500 bg-linear-to-r from-brand-800 via-brand-800/30 to-brand-800/10 ;
}
.sublink-default {
	@apply text-brand-100/70 hover:hover:bg-linear-to-r from-brand-800 via-brand-800/30 to-brand-800/10 hover:text-neutral-100;
}
.sidebar-link {
	@apply flex items-center gap-2 py-4 px-5  transition;
}
.sidebar-sublink {
	@apply block rounded-xs py-3.5 pl-9 pr-5 text-sm transition;
}
</style>
