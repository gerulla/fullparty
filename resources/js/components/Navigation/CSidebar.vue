<script setup>
import {Link, usePage} from "@inertiajs/vue3";
import {computed, ref, watch} from "vue";
import {useI18n} from "vue-i18n";
import DevelopmentNotice from "@/components/DevelopmentNotice.vue";

const { t } = useI18n();
const loginHref = route('login');
const page = usePage()
const isAuthenticated = computed(() => Boolean(page.props.auth?.user))
const authLink = (href, icon, label) => ({
	label,
	icon,
	href: isAuthenticated.value ? href : loginHref,
	activeHref: href,
})

const top = computed(() => [
	authLink(route('dashboard'), 'i-lucide-house', t('navigation.sidebar.dashboard')),
	authLink('/dashboard/runs', 'i-lucide-calendar-days', t('navigation.sidebar.runs')),
])

const account = computed(() => [
	authLink(route('account.characters'), 'i-lucide-user-circle', t('navigation.sidebar.characters')),
	authLink(route('account.notifications.index'), 'i-lucide-bell', t('navigation.sidebar.notifications')),
	authLink(route('account.applications'), 'i-lucide-file-text', t('navigation.sidebar.applications')),
])

const groups = computed(() => [
	{ label: t('navigation.sidebar.groups'), href: route('groups.index'), icon: 'i-lucide-shield' },
])

const admin = computed(() => [
	{ label: t('navigation.sidebar.character_definitions'), href: '/admin/character-data', icon: 'i-lucide-user-pen' },
	{ label: t('navigation.sidebar.admin_audit_log'), href: '/admin/audit-log', icon: 'i-lucide-scroll-text' },
	{ label: t('navigation.sidebar.system_notifications'), href: '/admin/system-notifications', icon: 'i-lucide-megaphone' },
	{ label: t('navigation.sidebar.activity_types'), href: '/admin/activity-types', icon: 'i-lucide-file-pen' },
	{ label: t('navigation.sidebar.pulse'), href: '/pulse', icon: 'i-lucide-activity', external: true },
])

const full_logo = "/logos/full.png";
const compact_logo = "/logos/compact.png";
const currentUrl = computed(() => page.url)
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin))
const groupQuickLinks = computed(() => page.props.navigation?.group_quick_links ?? {
	owned: [],
	moderated: [],
	member: [],
})
const groupDrawerOpen = ref({
	owned: false,
	moderated: false,
	member: false,
})

const groupQuickLinkSections = computed(() => [
	{
		key: 'owned',
		label: t('navigation.sidebar.owned_groups'),
		icon: 'i-lucide-crown',
		items: groupQuickLinks.value.owned,
	},
	{
		key: 'moderated',
		label: t('navigation.sidebar.moderated_groups'),
		icon: 'i-lucide-shield-check',
		items: groupQuickLinks.value.moderated,
	},
	{
		key: 'member',
		label: t('navigation.sidebar.member_groups'),
		icon: 'i-lucide-users',
		items: groupQuickLinks.value.member,
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
</script>

<template>
	<UDashboardSidebar :default-size="15"  :ui="{ footer: '',  body: 'px-2' }" class="bg-brand-950">
		<template #header="{ collapsed }">
			<div v-if="!collapsed" class="w-full h-full mt-4">
				<img :src="full_logo" class="h-full w-auto mx-auto " alt="FullParty Logo">
			</div>
			<img v-else :src="compact_logo" class="w-full h-auto" alt="FullParty Logo">
		</template>

		<template #default="{ collapsed }">
			<div class="mt-4 flex flex-col w-full h-full ">
				<Link
					v-for="item in top"
					:key="item.activeHref"
					:href="item.href"
					class="sidebar-link"
					:class="currentUrl.startsWith(item.activeHref) ? 'link-highlighted': 'link-default'"
				>
					<UIcon :name="item.icon" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
					<span v-if="!collapsed">{{ item.label }}</span>
				</Link>

				<h1 v-if="!collapsed" class="sidebar-separator">{{t('navigation.sidebar.account')}}</h1>
				<div v-else class="sidebar-line-separator"></div>

				<Link
					v-for="item in account"
					:key="item.activeHref"
					:href="item.href"
					class="sidebar-link"
					:class="currentUrl.startsWith(item.activeHref) ? 'link-highlighted': 'link-default'"
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
					:class="currentUrl === item.href ? 'link-highlighted': 'link-default'"
				>
					<UIcon :name="item.icon" :class="!collapsed ? 'sidebar-link-icon' : 'sidebar-link-icon-large'" />
					<span v-if="!collapsed">{{ item.label }}</span>
				</Link>

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
						:class="!item.external && currentUrl.startsWith(item.href) ? 'link-highlighted': 'link-default'"
						:target="item.external ? '_blank' : undefined"
						:rel="item.external ? 'noopener noreferrer' : undefined"
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
.sublink-highlighted {
	@apply text-neutral-100 bg-brand-800/80;
}
.sublink-default {
	@apply text-brand-100/70 hover:bg-brand-900/60 hover:text-neutral-100;
}
.sidebar-link {
	@apply flex items-center gap-2 py-2 px-5  transition;
}
.sidebar-sublink {
	@apply block rounded-xs py-1.5 pl-9 pr-5 text-sm transition;
}
</style>
