<script setup lang="ts">
import { ref } from 'vue'
import type { DropdownMenuItem } from '@nuxt/ui'
import { router } from '@inertiajs/vue3'
import {route} from "ziggy-js";

const logoutRedirect = () => {
	router.post(route('logout'))
}

const items = ref<DropdownMenuItem[][]>([
	[
		{
			label: 'Profile',
			icon: 'i-lucide-user'
		},
		{
			label: 'Settings',
			icon: 'i-lucide-cog',
		},
	],
	[
		{
			label: 'Logout',
			icon: 'i-lucide-log-out',
			color: 'error',
			onSelect() {
				logoutRedirect();
			}
		}
	]
])
</script>

<template>
	<UDropdownMenu :items="items">
		<div class="flex items-center gap-2 cursor-pointer hover:bg-neutral-100 dark:hover:bg-neutral-800 rounded-sm px-2 py-2">
			<UUser
				name="Giki Chomusuke"
				:avatar="{
					src: 'https://img2.finalfantasyxiv.com/f/15cff6ad5af687333d4ae7545c7b4ec4_7206469080400ed57a5373d0a9c55c59fc0.jpg?1774692966',
					loading: 'lazy',
					icon: 'i-lucide-image'
				}"
				description="G3ru1a"
				:chip="{
					color: 'success',
					position: 'top-right'
				}"
			/>
			<UIcon name="i-lucide-chevron-down" class="w-4 h-4" />
		</div>
	</UDropdownMenu>
</template>

<style scoped>

</style>