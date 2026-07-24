<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const page = usePage()
const { t } = useI18n()

const user = computed(() => page.props.auth?.user ?? null)
const plannerRoutes = computed(() => page.props.planner?.routes ?? {})
const displayName = computed(() => user.value?.primary_character?.name ?? user.value?.name ?? '')
const avatarUrl = computed(() => user.value?.primary_character?.avatar_url ?? user.value?.avatar_url ?? null)

const navigationItems = computed(() => [
	{
		label: t('planner.navigation.file'),
		icon: 'i-lucide-file',
		children: [
			{
				label: t('planner.navigation.new_plan'),
				icon: 'i-lucide-file-plus-2',
				disabled: true,
			},
			{
				label: t('planner.navigation.open_plan'),
				icon: 'i-lucide-folder-open',
				disabled: true,
			},
		],
	},
	{
		label: t('planner.navigation.edit'),
		icon: 'i-lucide-pencil',
		children: [
			{
				label: t('planner.navigation.undo'),
				icon: 'i-lucide-undo-2',
				disabled: true,
			},
			{
				label: t('planner.navigation.redo'),
				icon: 'i-lucide-redo-2',
				disabled: true,
			},
		],
	},
	{
		label: t('planner.navigation.preview'),
		icon: 'i-lucide-play',
		disabled: true,
	},
])

const accountItems = computed(() => [
	[
		{
			label: t('planner.account.back_to_fullparty'),
			icon: 'i-lucide-arrow-left',
			onSelect: () => window.location.assign(plannerRoutes.value.dashboard),
		},
		{
			label: t('navigation.topbar.menu.settings'),
			icon: 'i-lucide-cog',
			onSelect: () => window.location.assign(plannerRoutes.value.settings),
		},
	],
	[
		{
			label: t('navigation.topbar.menu.logout'),
			icon: 'i-lucide-log-out',
			color: 'error',
			onSelect: submitLogout,
		},
	],
])

function submitLogout() {
	const form = document.createElement('form')
	const token = document.createElement('input')

	form.method = 'POST'
	form.action = plannerRoutes.value.logout
	token.type = 'hidden'
	token.name = '_token'
	token.value = page.props.planner?.csrf_token ?? ''

	form.appendChild(token)
	document.body.appendChild(form)
	form.submit()
}
</script>

<template>
	<UHeader
		:title="`FullParty ${t('planner.title')}`"
		:to="plannerRoutes.dashboard"
	>
		<template #title>
			<span class="flex min-w-0 items-center gap-3">
				<img :src="'/logos/compact.png'" alt="FullParty" class="h-9 w-auto object-contain sm:hidden">
				<img :src="'/logos/full.png'" alt="FullParty" class="hidden h-10 w-auto object-contain sm:block">
				<UBadge
					:label="t('planner.title')"
					color="primary"
					variant="subtle"
					size="xl"
					class="shrink-0"
				/>
			</span>
		</template>

		<UNavigationMenu :items="navigationItems" />

		<template #right>
			<UDropdownMenu
				v-if="user"
				:items="accountItems"
				arrow
				:content="{ align: 'end', side: 'bottom' }"
				:ui="{ content: 'min-w-56' }"
			>
				<UButton color="neutral" variant="ghost" trailing-icon="i-lucide-chevron-down">
					<UAvatar :src="avatarUrl" :alt="displayName" icon="i-lucide-user" size="md" />
					<span class="hidden max-w-40 truncate text-sm font-medium sm:block">{{ displayName }}</span>
				</UButton>
			</UDropdownMenu>

			<div v-else class="flex items-center gap-1 sm:gap-2">
				<UButton
					:to="plannerRoutes.login"
					external
					:label="t('auth.login')"
					icon="i-lucide-log-in"
					color="neutral"
					variant="ghost"
					:ui="{ label: 'hidden sm:inline' }"
				/>
				<UButton
					:to="plannerRoutes.register"
					external
					:label="t('auth.register')"
					icon="i-lucide-user-round-plus"
					color="primary"
					:ui="{ label: 'hidden sm:inline' }"
				/>
			</div>
		</template>

		<template #body>
			<UNavigationMenu :items="navigationItems" orientation="vertical" class="-mx-2.5" />
		</template>
	</UHeader>
</template>
