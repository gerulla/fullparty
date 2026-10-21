<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { router } from '@inertiajs/vue3'
import {route} from "ziggy-js";
import {useI18n} from "vue-i18n";
import {computed} from 'vue';
import { usePage } from '@inertiajs/vue3'
import { useAppearance } from '@/composables/useAppearance'
import { usePersistentLocale } from '@/composables/usePersistentLocale'
import { useTimeDisplayPreference } from '@/composables/useTimeDisplayPreference'
import { flagByLocaleCode } from '@/utils/localeFlags'

const page = usePage()
const props = defineProps<{ compact?: boolean; mobile?: boolean }>()

const user = computed(() => page.props.auth?.user)
const { t } = useI18n();
const { isDark, toggleAppearance } = useAppearance()
const { currentLocale, localeOptions, updateLocale } = usePersistentLocale()
const { timeDisplayMode, isSaving, updateMode } = useTimeDisplayPreference()
const logoutRedirect = () => {
	router.post(route('logout'))
}

const goToLogin = () => {
	router.get(route('login'))
}

const goToRegister = () => {
	router.get(route('register'))
}

const preferences = computed<DropdownMenuItem[]>(() => [
    {
        label: t(`navigation.topbar.appearance.${isDark.value ? 'light' : 'dark'}`),
        icon: isDark.value ? 'i-lucide-sun' : 'i-lucide-moon',
        onSelect(event) {
            event.preventDefault()
            toggleAppearance()
        },
    },
    {
        label: t('navigation.topbar.language'),
        icon: 'i-lucide-languages',
        children: localeOptions.value.map(locale => ({
            label: locale.name,
            type: 'checkbox',
            checked: locale.code === currentLocale.value,
            avatar: { src: flagByLocaleCode[locale.code], alt: locale.name },
            slot: 'locale',
            onSelect: () => { void updateLocale(locale.code) },
        })),
    },
    ...(user.value ? [{
        label: t('navigation.topbar.time_display.label'),
        icon: 'i-lucide-clock',
        slot: 'time',
        disabled: isSaving.value,
        loading: isSaving.value,
        onSelect() {
            void updateMode(timeDisplayMode.value === 'server' ? 'local' : 'server')
        },
    }] : []),
])

const items = computed<DropdownMenuItem[][]>(() => user.value ? [
	[
		{
			label: t('navigation.topbar.menu.profile'),
			icon: 'i-lucide-user',
			onSelect(){
				if (user.value) router.get(route('profile.show', { user: user.value.id }));
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
	],
    ...(props.mobile ? [preferences.value] : []),
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
] : [
    [
        { label: t('auth.login'), icon: 'i-lucide-log-in', onSelect: goToLogin },
        { label: t('auth.register'), icon: 'i-lucide-user-round-plus', onSelect: goToRegister },
    ],
    ...(props.mobile ? [preferences.value] : []),
])
</script>

<template>
	<UDropdownMenu
        v-if="user || mobile"
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
                :class="{ 'bg-elevation-1': open, 'size-11 justify-center': mobile }"
                :aria-label="t('navigation.topbar.menu.label')"
            >
			<div v-if="user" class="relative" :class="compact ? '' : 'sm:hidden'">
				<UAvatar
					:src="user.primary_character ? user.primary_character.avatar_url : user.avatar_url"
					icon="i-lucide-image"
					size="md"
					:alt="user.primary_character ? user.primary_character.name : user.name"
				/>
				<span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full border-2 border-elevation-1 bg-success" />
			</div>
			<UUser
                v-if="user && !compact"
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
            <UIcon v-if="!user" name="i-lucide-circle-user" class="size-6" />
			<UIcon v-if="!mobile" name="i-lucide-chevron-down" class="h-4 w-4" />
            </UButton>
        </template>
        <template #locale-leading="{ item }">
            <img v-if="item.avatar?.src" :src="item.avatar.src" alt="" class="h-4 w-5 rounded-[2px] border border-default object-cover" />
        </template>
        <template #time-trailing>
            <span class="text-xs font-semibold text-muted">{{ timeDisplayMode === 'server' ? 'ST' : 'LT' }}</span>
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
