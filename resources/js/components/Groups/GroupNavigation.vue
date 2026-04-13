<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { usePage } from '@inertiajs/vue3'

const props = defineProps<{
	group: {
		slug: string
		name?: string
	}
}>()

const page = usePage()

const items = computed<NavigationMenuItem[][]>(() => [
	[
		{
			label: 'General',
			icon: 'i-lucide-layout-dashboard',
			to: route('groups.dashboard', props.group.slug),
			active: page.url === route('groups.dashboard', props.group.slug, false),
		},
		{
			label: 'Runs',
			icon: 'i-lucide-calendar-range',
			to: route('groups.dashboard.runs.index', props.group.slug),
			active: page.url.startsWith(route('groups.dashboard.runs.index', props.group.slug, false)),
		},
	],
	[
		{
			label: 'Settings',
			icon: 'i-lucide-settings-2',
			to: route('groups.dashboard.settings', props.group.slug),
			active: page.url.startsWith(route('groups.dashboard.settings', props.group.slug, false)),
		}
	]
])
</script>

<template>
	<UDashboardToolbar>
		<UNavigationMenu :items="items" highlight class="flex-1" />
	</UDashboardToolbar>
</template>
