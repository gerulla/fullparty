<script setup lang="ts">
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import { Link, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { NavigationMenuItem } from '@nuxt/ui'

const props = defineProps<{
	group: {
		slug: string
		name?: string
		current_user_role?: 'owner' | 'admin' | 'moderator' | 'member' | null
		permissions?: {
			can_manage_group?: boolean
			can_update_group_settings?: boolean
			can_manage_members?: boolean
			can_manage_discovery?: boolean
			can_manage_activities?: boolean
			can_view_members?: boolean
			can_review_membership_applications?: boolean
			can_manage_membership_application_form?: boolean
		}
		features?: {
			availability_scheduler_enabled?: boolean
			availability_minimum_role?: 'member' | 'moderator'
			statistics_enabled?: boolean
			leaderboard_enabled?: boolean
		}
	}
}>()

const page = usePage()
const { t } = useI18n()
const activeMobileMenu = ref<"info" | "moderation" | null>(null)

const isRouteActive = (href: string) => (
	page.url === href
	|| page.url.startsWith(`${href}/`)
	|| page.url.startsWith(`${href}?`)
	|| page.url.startsWith(`${href}#`)
)

const routePath = (name: string) => route(name, props.group.slug, false)

const dashboardHref = computed(() => route('groups.dashboard', props.group.slug))
const dashboardPath = computed(() => routePath('groups.dashboard'))
const activitiesHref = computed(() => route('groups.dashboard.activities.index', props.group.slug))
const activitiesPath = computed(() => routePath('groups.dashboard.activities.index'))
const publicActivitiesPath = computed(() => `/groups/${props.group.slug}/activities/`)
const statisticsHref = computed(() => route('groups.dashboard.statistics', props.group.slug))
const statisticsPath = computed(() => routePath('groups.dashboard.statistics'))
const leaderboardHref = computed(() => route('groups.dashboard.leaderboard', props.group.slug))
const leaderboardPath = computed(() => routePath('groups.dashboard.leaderboard'))
const legacyLeaderboardHref = computed(() => route('groups.dashboard.legacy-leaderboard', props.group.slug))
const legacyLeaderboardPath = computed(() => routePath('groups.dashboard.legacy-leaderboard'))
const membersHref = computed(() => route('groups.dashboard.members', props.group.slug))
const membersPath = computed(() => routePath('groups.dashboard.members'))
const availabilityHref = computed(() => route('groups.dashboard.availability', props.group.slug))
const availabilityPath = computed(() => routePath('groups.dashboard.availability'))
const delubrumReginaeSavageHref = computed(() => route('groups.dashboard.content.delubrum-reginae-savage', props.group.slug))
const delubrumReginaeSavagePath = computed(() => routePath('groups.dashboard.content.delubrum-reginae-savage'))
const forkedTowerBloodHref = computed(() => route('groups.dashboard.content.forked-tower-blood', props.group.slug))
const forkedTowerBloodPath = computed(() => routePath('groups.dashboard.content.forked-tower-blood'))
const membershipApplicationsHref = computed(() => route('groups.dashboard.membership-applications.index', props.group.slug))
const membershipApplicationsPath = computed(() => routePath('groups.dashboard.membership-applications.index'))
const membershipApplicationFormPath = computed(() => routePath('groups.dashboard.membership-application-form.edit'))
const auditLogHref = computed(() => route('groups.dashboard.audit-log', props.group.slug))
const auditLogPath = computed(() => routePath('groups.dashboard.audit-log'))
const discoverySettingsHref = computed(() => route('groups.dashboard.discovery-settings', props.group.slug))
const discoverySettingsPath = computed(() => routePath('groups.dashboard.discovery-settings'))
const settingsHref = computed(() => route('groups.dashboard.settings', props.group.slug))
const settingsPath = computed(() => routePath('groups.dashboard.settings'))
const discordIntegrationHref = computed(() => route('groups.dashboard.discord-integration', props.group.slug))
const discordIntegrationPath = computed(() => routePath('groups.dashboard.discord-integration'))
const isPublicActivityRoute = computed(() => page.url.startsWith(publicActivitiesPath.value))
const isGroupMember = computed(() => Boolean(
	props.group.current_user_role
	|| props.group.permissions?.can_view_members,
))
const showsStatistics = computed(() => props.group.features?.statistics_enabled ?? true)
const showsLeaderboard = computed(() => props.group.features?.leaderboard_enabled ?? true)
const canUseAvailability = computed(() => (
	props.group.features?.availability_minimum_role !== 'moderator'
	|| ['owner', 'admin', 'moderator'].includes(props.group.current_user_role ?? '')
	|| Boolean(props.group.permissions?.can_manage_members)
))
const showsAvailability = computed(() => (
	isGroupMember.value
	&&
	(props.group.features?.availability_scheduler_enabled ?? false)
	&& canUseAvailability.value
))
const showsLegacyLeaderboard = computed(() => props.group.slug === 'ftel' && showsLeaderboard.value)
const canUpdateGroupSettings = computed(() => Boolean(
	props.group.permissions?.can_update_group_settings
	|| props.group.permissions?.can_manage_discovery
	|| props.group.permissions?.can_manage_group,
))

const isManagementUser = computed(() => Boolean(
	props.group.permissions?.can_manage_group
	|| props.group.permissions?.can_update_group_settings
	|| props.group.permissions?.can_manage_members
	|| props.group.permissions?.can_manage_discovery
	|| props.group.permissions?.can_manage_activities
	|| props.group.permissions?.can_review_membership_applications
	|| props.group.permissions?.can_manage_membership_application_form,
))

const desktopLinkItem = (item: NavigationMenuItem & { to: string }): NavigationMenuItem => ({
	...item,
})

const dataMenuItems = computed<NavigationMenuItem[]>(() => [
	...(showsStatistics.value ? [desktopLinkItem({
		label: t('groups.index.navigation.statistics'),
		icon: 'i-lucide-chart-no-axes-combined',
		to: statisticsHref.value,
		active: isRouteActive(statisticsPath.value),
	})] : []),
	...(showsLeaderboard.value ? [desktopLinkItem({
		label: t('groups.index.navigation.leaderboard'),
		icon: 'i-lucide-trophy',
		to: leaderboardHref.value,
		active: isRouteActive(leaderboardPath.value),
	})] : []),
	...(showsLegacyLeaderboard.value ? [desktopLinkItem({
		label: t('groups.index.navigation.legacy_leaderboard'),
		icon: 'i-lucide-archive',
		to: legacyLeaderboardHref.value,
		active: isRouteActive(legacyLeaderboardPath.value),
	})] : []),
])

const desktopModerationMenuItems = computed<NavigationMenuItem[]>(() => [
	...(props.group.permissions?.can_review_membership_applications ? [desktopLinkItem({
		label: t('groups.index.navigation.membership_applications'),
		icon: 'i-lucide-clipboard-check',
		to: membershipApplicationsHref.value,
		active: isRouteActive(membershipApplicationsPath.value),
	})] : []),
	...(props.group.permissions?.can_manage_discovery ? [desktopLinkItem({
		label: t('groups.index.navigation.discovery_settings'),
		icon: 'i-lucide-radar',
		to: discoverySettingsHref.value,
		active: isRouteActive(discoverySettingsPath.value),
	})] : []),
])

const desktopConfigurationMenuItems = computed<NavigationMenuItem[]>(() => [
	...(props.group.permissions?.can_manage_membership_application_form ? [desktopLinkItem({
		label: t('groups.index.navigation.application_form'),
		icon: 'i-lucide-list-checks',
		to: route('groups.dashboard.membership-application-form.edit', props.group.slug),
		active: isRouteActive(membershipApplicationFormPath.value),
	})] : []),
	...(props.group.permissions?.can_manage_group ? [desktopLinkItem({
		label: t('groups.index.navigation.discord_integration'),
		icon: 'ic:baseline-discord',
		to: discordIntegrationHref.value,
		active: isRouteActive(discordIntegrationPath.value),
	})] : []),
	...(canUpdateGroupSettings.value ? [desktopLinkItem({
		label: t('groups.index.navigation.settings'),
		icon: 'i-lucide-settings-2',
		to: settingsHref.value,
		active: isRouteActive(settingsPath.value),
	})] : []),
])

const desktopContentMenuItems = computed<NavigationMenuItem[]>(() => [
	desktopLinkItem({
		label: t('groups.index.navigation.delubrum_reginae_savage'),
		icon: 'i-lucide-castle',
		to: delubrumReginaeSavageHref.value,
		active: isRouteActive(delubrumReginaeSavagePath.value),
	}),
	desktopLinkItem({
		label: t('groups.index.navigation.forked_tower_blood'),
		icon: 'i-lucide-droplets',
		to: forkedTowerBloodHref.value,
		active: isRouteActive(forkedTowerBloodPath.value),
	}),
])

const desktopLeftItems = computed<NavigationMenuItem[]>(() => [
	desktopLinkItem({
		label: t('groups.index.navigation.general'),
		icon: 'i-lucide-layout-dashboard',
		to: dashboardHref.value,
		active: page.url === dashboardPath.value,
	}),
	desktopLinkItem({
		label: t('groups.index.navigation.activities'),
		icon: 'i-lucide-calendar-range',
		to: activitiesHref.value,
		active: isRouteActive(activitiesPath.value) || isPublicActivityRoute.value,
	}),
	...(isGroupMember.value && dataMenuItems.value.length > 0 ? [{
		label: t('groups.index.navigation.data'),
		icon: 'i-lucide-chart-no-axes-combined',
		active: dataMenuItems.value.some((item) => item.active),
		children: dataMenuItems.value,
	}] : []),
	...(props.group.permissions?.can_view_members ? [desktopLinkItem({
		label: t('groups.index.navigation.members'),
		icon: 'i-lucide-users',
		to: membersHref.value,
		active: isRouteActive(membersPath.value),
	})] : []),
	...(showsAvailability.value ? [desktopLinkItem({
		label: t('groups.index.navigation.availability'),
		icon: 'i-lucide-calendar-clock',
		to: availabilityHref.value,
		active: isRouteActive(availabilityPath.value),
	})] : []),
])

const desktopRightItems = computed<NavigationMenuItem[]>(() => [
	...(props.group.permissions?.can_manage_members ? [{
		label: t('groups.index.navigation.content'),
		icon: 'i-lucide-book-open-text',
		active: desktopContentMenuItems.value.some((item) => item.active),
		children: desktopContentMenuItems.value,
	}] : []),
	...(desktopModerationMenuItems.value.length > 0 ? [{
		label: t('groups.index.navigation.moderation'),
		icon: 'i-lucide-shield-check',
		active: desktopModerationMenuItems.value.some((item) => item.active),
		children: desktopModerationMenuItems.value,
	}] : []),
	...(desktopConfigurationMenuItems.value.length > 0 ? [{
		label: t('groups.index.navigation.configuration'),
		icon: 'i-lucide-sliders-horizontal',
		active: desktopConfigurationMenuItems.value.some((item) => item.active),
		children: desktopConfigurationMenuItems.value,
	}] : []),
	...(props.group.permissions?.can_manage_members ? [desktopLinkItem({
		label: t('groups.index.navigation.audit_log'),
		icon: 'i-lucide-scroll-text',
		to: auditLogHref.value,
		active: isRouteActive(auditLogPath.value),
	})] : []),
])

const desktopNavigationUi = {
	root: 'relative z-50 !overflow-visible',
	list: '!overflow-visible',
	viewportWrapper: 'z-50 !overflow-visible',
	viewport: 'z-50 !overflow-visible rounded-none border border-default bg-elevated/95 shadow-xl backdrop-blur-xl ring-0',
	content: 'w-60 !max-h-none !overflow-visible',
	childList: 'gap-1 p-1.5',
	childLink: 'rounded-none',
}

const settingsActive = computed(() => isRouteActive(settingsPath.value))

const infoMenuItems = computed(() => [
	...(showsAvailability.value ? [{
		label: t('groups.index.navigation.availability'),
		icon: 'i-lucide-calendar-clock',
		href: availabilityHref.value,
		active: isRouteActive(availabilityPath.value),
	}] : []),
	...(showsStatistics.value ? [{
		label: t('groups.index.navigation.statistics'),
		icon: 'i-lucide-chart-no-axes-combined',
		href: statisticsHref.value,
		active: isRouteActive(statisticsPath.value),
	}] : []),
	...(showsLeaderboard.value ? [{
		label: t('groups.index.navigation.leaderboard'),
		icon: 'i-lucide-trophy',
		href: leaderboardHref.value,
		active: isRouteActive(leaderboardPath.value),
	}] : []),
	...(showsLegacyLeaderboard.value ? [{
		label: t('groups.index.navigation.legacy_leaderboard'),
		icon: 'i-lucide-archive',
		href: legacyLeaderboardHref.value,
		active: isRouteActive(legacyLeaderboardPath.value),
	}] : []),
	...(props.group.permissions?.can_manage_members ? [{
		label: t('groups.index.navigation.audit_log'),
		icon: 'i-lucide-scroll-text',
		href: auditLogHref.value,
		active: isRouteActive(auditLogPath.value),
	}] : []),
])

const moderationMenuItems = computed(() => [
	...(props.group.permissions?.can_manage_discovery ? [{
		label: t('groups.index.navigation.discovery_settings'),
		icon: 'i-lucide-radar',
		href: discoverySettingsHref.value,
		active: isRouteActive(discoverySettingsPath.value),
	}] : []),
	...(props.group.permissions?.can_manage_membership_application_form ? [{
		label: t('groups.index.navigation.application_form'),
		icon: 'i-lucide-list-checks',
		href: route('groups.dashboard.membership-application-form.edit', props.group.slug),
		active: isRouteActive(membershipApplicationFormPath.value),
	}] : []),
	...(props.group.permissions?.can_review_membership_applications ? [{
		label: t('groups.index.navigation.membership_applications'),
		icon: 'i-lucide-clipboard-check',
		href: membershipApplicationsHref.value,
		active: isRouteActive(membershipApplicationsPath.value),
	}] : []),
	...(props.group.permissions?.can_view_members ? [{
		label: t('groups.index.navigation.members'),
		icon: 'i-lucide-users',
		href: membersHref.value,
		active: isRouteActive(membersPath.value),
	}] : []),
	...(props.group.permissions?.can_manage_group ? [{
		label: t('groups.index.navigation.discord_integration'),
		icon: 'ic:baseline-discord',
		href: discordIntegrationHref.value,
		active: isRouteActive(discordIntegrationPath.value),
	}] : []),
])

const activeMobileMenuItems = computed(() => {
	if (activeMobileMenu.value === "info") {
		return infoMenuItems.value
	}

	if (activeMobileMenu.value === "moderation") {
		return moderationMenuItems.value
	}

	return []
})

const toggleMobileMenu = (menu: "info" | "moderation") => {
	activeMobileMenu.value = activeMobileMenu.value === menu ? null : menu
}

const closeMobileMenu = () => {
	activeMobileMenu.value = null
}

const memberMobileItems = computed(() => [
	{
		label: t('groups.index.navigation.home'),
		icon: 'i-lucide-house',
		href: dashboardHref.value,
		active: page.url === dashboardPath.value,
	},
	...(showsAvailability.value || showsLegacyLeaderboard.value ? [{
		label: t('groups.index.navigation.info'),
		icon: 'i-lucide-info',
		href: null,
		menu: "info" as const,
		active: activeMobileMenu.value === "info"
			|| (showsAvailability.value && isRouteActive(availabilityPath.value))
			|| (showsStatistics.value && isRouteActive(statisticsPath.value))
			|| (showsLeaderboard.value && isRouteActive(leaderboardPath.value))
			|| isRouteActive(legacyLeaderboardPath.value),
	}] : showsLeaderboard.value ? [{
		label: t('groups.index.navigation.leaderboard'),
		icon: 'i-lucide-trophy',
		href: leaderboardHref.value,
		active: isRouteActive(leaderboardPath.value),
	}] : []),
	{
		label: t('groups.index.navigation.activities'),
		icon: 'i-lucide-swords',
		href: activitiesHref.value,
		active: isRouteActive(activitiesPath.value) || isPublicActivityRoute.value,
		primary: true,
	},
	{
		label: t('groups.index.navigation.members'),
		icon: 'i-lucide-users',
		href: membersHref.value,
		active: isRouteActive(membersPath.value),
	},
	...(showsAvailability.value ? [{
		label: t('groups.index.navigation.availability'),
		icon: 'i-lucide-calendar-clock',
		href: availabilityHref.value,
		active: isRouteActive(availabilityPath.value),
	}] : showsStatistics.value ? [{
		label: t('groups.index.navigation.statistics'),
		icon: 'i-lucide-chart-no-axes-combined',
		href: statisticsHref.value,
		active: isRouteActive(statisticsPath.value),
	}] : []),
])

const guestMobileItems = computed(() => [
	{
		label: t('groups.index.navigation.home'),
		icon: 'i-lucide-house',
		href: dashboardHref.value,
		active: page.url === dashboardPath.value,
	},
	{
		label: t('groups.index.navigation.activities'),
		icon: 'i-lucide-swords',
		href: activitiesHref.value,
		active: isRouteActive(activitiesPath.value) || isPublicActivityRoute.value,
	},
])

const managerMobileItems = computed(() => [
	{
		label: t('groups.index.navigation.home'),
		icon: 'i-lucide-house',
		href: dashboardHref.value,
		active: page.url === dashboardPath.value,
	},
	...(infoMenuItems.value.length > 0 ? [{
		label: t('groups.index.navigation.info'),
		icon: 'i-lucide-info',
		href: null,
		menu: "info" as const,
		active: activeMobileMenu.value === "info"
			|| (showsAvailability.value && isRouteActive(availabilityPath.value))
			|| (showsStatistics.value && isRouteActive(statisticsPath.value))
			|| (showsLeaderboard.value && isRouteActive(leaderboardPath.value))
			|| (showsLegacyLeaderboard.value && isRouteActive(legacyLeaderboardPath.value))
			|| isRouteActive(auditLogPath.value),
	}] : []),
	{
		label: t('groups.index.navigation.activities'),
		icon: 'i-lucide-swords',
		href: activitiesHref.value,
		active: isRouteActive(activitiesPath.value) || isPublicActivityRoute.value,
		primary: true,
	},
	{
		label: t('groups.index.navigation.moderation'),
		icon: 'i-lucide-shield-check',
		href: null,
		menu: "moderation" as const,
		active: activeMobileMenu.value === "moderation"
			|| isRouteActive(discoverySettingsPath.value)
			|| isRouteActive(membershipApplicationFormPath.value)
			|| isRouteActive(membershipApplicationsPath.value)
			|| isRouteActive(membersPath.value)
			|| isRouteActive(discordIntegrationPath.value),
	},
	{
		label: t('groups.index.navigation.settings'),
		icon: 'i-lucide-settings-2',
		href: settingsHref.value,
		active: settingsActive.value,
	},
])

const mobileItems = computed(() => {
	if (!isGroupMember.value) {
		return guestMobileItems.value
	}

	return isManagementUser.value ? managerMobileItems.value : memberMobileItems.value
})
</script>

<template>
	<UDashboardToolbar class="relative z-40 hidden !overflow-visible !overflow-x-visible !overflow-y-visible lg:flex">
		<UNavigationMenu
			:items="desktopLeftItems"
			variant="link"
			color="brand"
			highlight-color="brand"
			content-orientation="vertical"
			highlight
			:ui="desktopNavigationUi"
			class="relative z-50"
		/>
		<UNavigationMenu
			v-if="desktopRightItems.length > 0"
			:items="desktopRightItems"
			variant="link"
			color="brand"
			highlight-color="brand"
			content-orientation="vertical"
			highlight
			:ui="desktopNavigationUi"
			class="relative z-50 ml-auto"
		/>
	</UDashboardToolbar>

	<nav class="fixed inset-x-0 bottom-0 z-50 border-t border-white/10 bg-neutral-950/94 px-3 pt-2 pb-[calc(0.5rem+env(safe-area-inset-bottom))] shadow-[0_-18px_42px_rgba(0,0,0,0.38)] backdrop-blur-xl lg:hidden">
		<Transition
			enter-active-class="transition duration-200 ease-out"
			enter-from-class="translate-y-full opacity-0"
			enter-to-class="translate-y-0 opacity-100"
			leave-active-class="transition duration-150 ease-in"
			leave-from-class="translate-y-0 opacity-100"
			leave-to-class="translate-y-full opacity-0"
		>
			<div
				v-if="activeMobileMenuItems.length > 0"
				class="absolute inset-x-0 bottom-full -z-10 border-t border-white/10 bg-neutral-950/94 px-3 pt-2 pb-2 shadow-[0_-14px_34px_rgba(0,0,0,0.32)] backdrop-blur-xl"
			>
				<div
					class="mx-auto grid max-w-md items-end gap-1"
					:class="[
						activeMobileMenuItems.length === 1 ? 'grid-cols-1' : '',
						activeMobileMenuItems.length === 2 ? 'grid-cols-2' : '',
						activeMobileMenuItems.length === 3 ? 'grid-cols-3' : '',
						activeMobileMenuItems.length >= 4 ? 'grid-cols-4' : '',
					]"
				>
					<Link
						v-for="item in activeMobileMenuItems"
						:key="item.href"
						:href="item.href"
						class="group flex min-w-0 flex-col items-center justify-end gap-1 pt-1 text-center transition"
						@click="closeMobileMenu"
					>
						<span
							class="flex size-8 shrink-0 items-center justify-center rounded-sm border transition group-hover:text-toned"
							:class="item.active ? 'border-brand-400/35 bg-brand-500/10 text-brand' : 'border-transparent bg-transparent text-muted'"
						>
							<UIcon :name="item.icon" class="size-5" />
						</span>
						<span
							class="max-w-full truncate text-[10px] font-semibold leading-none"
							:class="item.active ? 'text-highlighted' : 'text-muted'"
						>
							{{ item.label }}
						</span>
					</Link>
				</div>
			</div>
		</Transition>

		<div
			class="mx-auto grid max-w-md items-end gap-1"
			:class="mobileItems.length === 2 ? 'grid-cols-2' : 'grid-cols-5'"
		>
			<template
				v-for="item in mobileItems"
				:key="`${item.label}-${item.href ?? 'button'}`"
			>
				<Link
					v-if="item.href"
					:href="item.href"
					class="group flex min-w-0 flex-col items-center justify-end gap-1 text-center transition"
					:class="item.primary ? '-mt-5' : 'pt-1'"
					@click="closeMobileMenu"
				>
					<span
						class="flex shrink-0 items-center justify-center border transition"
						:class="[
							item.primary
								? 'size-14 rounded-full border-brand-300/70 bg-brand-500 text-white shadow-lg shadow-brand-500/35'
								: 'size-8 rounded-sm border-transparent bg-transparent',
							item.active && !item.primary
								? 'text-brand'
								: item.primary
									? ''
									: 'text-muted group-hover:text-toned'
						]"
					>
						<UIcon :name="item.icon" :class="item.primary ? 'size-6' : 'size-5'" />
					</span>
					<span
						class="max-w-full truncate text-[10px] font-semibold leading-none"
						:class="[
							item.primary ? 'uppercase tracking-[0.16em]' : '',
							item.active || item.primary ? 'text-highlighted' : 'text-muted'
						]"
					>
						{{ item.label }}
					</span>
				</Link>
				<button
					v-else
					type="button"
					class="group flex min-w-0 flex-col items-center justify-end gap-1 pt-1 text-center transition"
					@click="item.menu ? toggleMobileMenu(item.menu) : undefined"
				>
					<span
						class="flex size-8 shrink-0 items-center justify-center rounded-sm border transition group-hover:text-toned"
						:class="item.active ? 'border-brand-400/35 bg-brand-500/10 text-brand' : 'border-transparent bg-transparent text-muted'"
					>
						<UIcon :name="item.icon" class="size-5" />
					</span>
					<span
						class="max-w-full truncate text-[10px] font-semibold leading-none"
						:class="item.active ? 'text-highlighted' : 'text-muted'"
					>
						{{ item.label }}
					</span>
				</button>
			</template>
		</div>
	</nav>
</template>
