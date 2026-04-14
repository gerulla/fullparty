<script setup lang="ts">
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { Link, usePage } from '@inertiajs/vue3'

const props = defineProps<{
	group: {
		slug: string
		name?: string
	}
}>()

const page = usePage()

const leftitems = computed(() => [
	{
		label: 'General',
		icon: 'i-lucide-layout-dashboard',
		href: route('groups.dashboard', props.group.slug),
		active: page.url === route('groups.dashboard', props.group.slug, false),
	},
	{
		label: 'Runs',
		icon: 'i-lucide-calendar-range',
		href: route('groups.dashboard.runs.index', props.group.slug),
		active: page.url.startsWith(route('groups.dashboard.runs.index', props.group.slug, false)),
	}
])

const rightitems = computed(() => [
	{
		label: 'Settings',
		icon: 'i-lucide-settings-2',
		href: route('groups.dashboard.settings', props.group.slug),
		active: page.url.startsWith(route('groups.dashboard.settings', props.group.slug, false)),
	}
])
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
