<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { router } from '@inertiajs/vue3'
import {route} from "ziggy-js";
import {useI18n} from "vue-i18n";
import {computed} from 'vue';
import { usePage } from '@inertiajs/vue3'

const page = usePage()

const user = computed(() => page.props.auth?.user)
const { t } = useI18n();
const logoutRedirect = () => {
	router.post(route('logout'))
}

const goToLogin = () => {
	router.get(route('login'))
}

const goToRegister = () => {
	router.get(route('register'))
}

const items = computed<DropdownMenuItem[][]>(() => [
	[
		{
			label: t('navigation.topbar.menu.profile'),
			icon: 'i-lucide-user',
		},
		{
			label: t('navigation.topbar.menu.settings'),
			icon: 'i-lucide-cog',
			onSelect(){
				router.get(route('settings'));
			}
		},
	],
	[
		{
			label: t('navigation.topbar.menu.logout'),
			icon: 'i-lucide-log-out',
			color: 'error',
			onSelect() {
				logoutRedirect()
			},
		},
	],
])
</script>

<template>
	<UDropdownMenu v-if="user" :items="items">
		<div class="flex items-center gap-2 cursor-pointer hover:bg-neutral-100 dark:hover:bg-neutral-800 rounded-sm px-2 py-2">
			<UUser
				:name="user.primary_character ? user.primary_character.name : user.name"
				:avatar="{
					src: user.primary_character ? user.primary_character.avatar_url : user.avatar_url,
					loading: 'lazy',
					icon: 'i-lucide-image'
				}"
				:description="user.name"
				:chip="{
					color: 'success',
					position: 'top-right'
				}"
			/>
			<UIcon name="i-lucide-chevron-down" class="w-4 h-4" />
		</div>
	</UDropdownMenu>

	<div v-else class="flex items-center gap-2">
		<UButton
			color="neutral"
			variant="ghost"
			size="lg"
			icon="i-lucide-log-in"
			:label="t('auth.login')"
			@click="goToLogin"
		/>
		<UButton
			color="neutral"
			variant="ghost"
			size="lg"
			icon="i-lucide-user-round-plus"
			:label="t('auth.register')"
			@click="goToRegister"
		/>
	</div>
</template>

<style scoped>

</style>
