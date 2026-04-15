<script setup lang="ts">
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { Link, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
	group: {
		slug: string
		name?: string
		permissions?: {
			can_manage_members?: boolean
		}
	}
}>()

const page = usePage()
const { t } = useI18n()

const leftitems = computed(() => [
	{
		label: t('groups.index.navigation.general'),
		icon: 'i-lucide-layout-dashboard',
		href: route('groups.dashboard', props.group.slug),
		active: page.url === route('groups.dashboard', props.group.slug, false),
	},
	{
		label: t('groups.index.navigation.runs'),
		icon: 'i-lucide-calendar-range',
		href: route('groups.dashboard.runs.index', props.group.slug),
		active: page.url.startsWith(route('groups.dashboard.runs.index', props.group.slug, false)),
	},
	{
		label: t('groups.index.navigation.members'),
		icon: 'i-lucide-users',
		href: route('groups.dashboard.members', props.group.slug),
		active: page.url.startsWith(route('groups.dashboard.members', props.group.slug, false)),
	}
])

const rightitems = computed(() => {
	if (!props.group.permissions?.can_manage_members) {
		return []
	}

	return [
		{
			label: t('groups.index.navigation.audit_log'),
			icon: 'i-lucide-scroll-text',
			href: route('groups.dashboard.audit-log', props.group.slug),
			active: page.url.startsWith(route('groups.dashboard.audit-log', props.group.slug, false)),
		},
		{
			label: t('groups.index.navigation.settings'),
			icon: 'i-lucide-settings-2',
			href: route('groups.dashboard.settings', props.group.slug),
			active: page.url.startsWith(route('groups.dashboard.settings', props.group.slug, false)),
		}
	]
})
</script>

<template>
	<UDashboardToolbar>
		<div class="flex h-full flex-wrap items-stretch gap-2 ">
			<Link
				v-for="item in leftitems"
				:key="item.href"
				:href="item.href"
				class="group-nav-link"
				:class="item.active ? 'group-nav-link-active' : 'group-nav-link-default'"
			>
				<UIcon :name="item.icon" class="h-4 w-4" />
				<span>{{ item.label }}</span>
			</Link>
		</div>
		<div class="ml-auto flex h-full flex-wrap items-stretch gap-2 ">
			<Link
				v-for="item in rightitems"
				:key="item.href"
				:href="item.href"
				class="group-nav-link"
				:class="item.active ? 'group-nav-link-active' : 'group-nav-link-default'"
			>
				<UIcon :name="item.icon" class="h-4 w-4" />
				<span>{{ item.label }}</span>
			</Link>
		</div>
	</UDashboardToolbar>
</template>

<style scoped>
@reference '../../../css/app.css';
.group-nav-link {
	@apply inline-flex items-center gap-2 border-b-0 rounded-none px-3 py-2 text-sm font-normal transition;
}

.group-nav-link-active {
	@apply text-brand border-b border-b-brand;
}

.group-nav-link-default {
	@apply text-muted hover:border-b;
}
</style>
