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
			onSelect(){
				router.get(route('dashboard'));
			}
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
			label: t('navigation.topbar.menu.my_characters'),
			icon: 'i-lucide-user-circle',
			onSelect(){
				router.get(route('account.characters'));
			}
		},
		{
			label: t('navigation.topbar.menu.my_groups'),
			icon: 'i-lucide-crown',
			onSelect(){
				router.get(route('groups.index'));
			}
		},
		{
			label: t('navigation.topbar.menu.my_requests'),
			icon: 'i-lucide-inbox',
			onSelect(){
				router.get(route('groups.requests.index'));
			}
		},
	],
	[
		{
			label: t('navigation.topbar.menu.logout'),
			icon: 'i-lucide-log-out',
			color: 'error',
            ui: {
                item: 'text-error data-highlighted:text-error',
                itemLeadingIcon: 'text-error group-data-highlighted:text-error',
            },
			onSelect() {
				logoutRedirect()
			},
		},
	],
])
</script>

<template>
	<UDropdownMenu
        v-if="user"
        arrow
        :items="items"
        :content="{ side: 'bottom', align: 'end', collisionPadding: 8 }"
        :ui="{
            content: 'min-w-[max(11rem,var(--reka-dropdown-menu-trigger-width))] max-w-[calc(100vw-1rem)] bg-elevation-1 text-default rounded-md ring-default',
            arrow: 'fill-elevation-1 stroke-default',
            group: 'p-0',
            item: 'px-4 py-3 cursor-pointer text-default before:hidden transition-colors data-highlighted:bg-elevation-3 data-highlighted:text-highlighted',
            itemLabel: 'text-inherit',
            itemLeadingIcon: 'text-muted group-data-highlighted:text-default',
        }"
    >
        <template #default="{ open }">
            <UButton
                color="neutral"
                variant="ghost"
                class="gap-2 rounded-md px-1.5 py-1.5 sm:px-2 sm:py-2 hover:bg-elevated focus-visible:bg-transparent focus-visible:hover:bg-elevated focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                :class="{ 'bg-elevation-1': open }"
            >
			<div class="relative sm:hidden">
				<UAvatar
					:src="user.primary_character ? user.primary_character.avatar_url : user.avatar_url"
					icon="i-lucide-image"
					size="md"
					:alt="user.primary_character ? user.primary_character.name : user.name"
				/>
				<span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full border-2 border-elevation-1 bg-success" />
			</div>
			<UUser
				class="hidden sm:flex"
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
			<UIcon name="i-lucide-chevron-down" class="h-4 w-4" />
            </UButton>
        </template>
	</UDropdownMenu>

	<div v-else class="flex items-center gap-2">
		<UButton
			color="neutral"
			variant="ghost"
			size="lg"
			icon="i-lucide-log-in"
			:label="t('auth.login')"
            class="rounded-md hover:bg-elevated"
			@click="goToLogin"
		/>
		<UButton
			color="neutral"
			variant="ghost"
			size="lg"
			icon="i-lucide-user-round-plus"
			:label="t('auth.register')"
            class="rounded-md hover:bg-elevated"
			@click="goToRegister"
		/>
	</div>
</template>

<style scoped>

</style>
