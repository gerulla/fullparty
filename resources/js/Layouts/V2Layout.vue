<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLocaleSelect from '@/components/Navigation/AppLocaleSelect.vue'
import GlobalSearchBox from '@/components/Navigation/GlobalSearchBox.vue'
import AppearanceToggle from '@/components/Navigation/AppearanceToggle.vue'
import NotificationBell from '@/components/Navigation/NotificationBell.vue'
import TimeDisplayModeToggle from '@/components/Navigation/TimeDisplayModeToggle.vue'
import UserMenuV2 from '@/components/Navigation/UserMenuV2.vue'
import { useAppearance } from '@/composables/useAppearance'
import { usePersistentLocale } from '@/composables/usePersistentLocale'
import { useUiTheme } from '@/composables/useUiTheme'

useUiTheme('medas')

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)
const { isDark } = useAppearance()
const { currentLocale, currentUiLocale } = usePersistentLocale()
const compactLogo = '/logos/compact.png'
const fullLogo = '/logos/full.png'
</script>

<template>
    <UApp :locale="currentUiLocale" class="font-inter">
        <div class="flex min-h-dvh flex-col text-default font-inter">
            <UHeader
                title="FullParty"
                :to="route('ui-v2.home', { locale: currentLocale })"
                :toggle="false"
                class="h-auto min-h-(--ui-header-height) bg-elevation-1 dark:bg-elevation-2"
                :ui="{
                    container: 'max-w-none min-h-(--ui-header-height) flex-wrap px-4 py-2 sm:px-6 lg:px-8 xl:flex-nowrap xl:py-0',
                    left: 'min-w-0 flex-1',
                    center: 'order-3 flex w-full min-w-0 justify-center xl:order-none xl:w-auto xl:flex-1',
                    right: 'min-w-0 flex-1 gap-1 sm:gap-1.5',
                }"
            >
                <GlobalSearchBox v-if="user" align="center" class="w-full max-w-xl xl:max-w-lg" />
                <template #title>
                    <span class="flex min-w-0 items-center gap-3">
                        <span
                            class="inline-flex sm:hidden"
                            :class="{ 'bg-brand-950 mask-contain mask-center mask-no-repeat': !isDark }"
                            :style="isDark ? undefined : { maskImage: `url(${compactLogo})` }"
                        >
                            <img :src="compactLogo" alt="FullParty" class="h-9 w-auto object-contain" :class="{ 'opacity-0': !isDark }">
                        </span>
                        <span
                            class="hidden sm:inline-flex"
                            :class="{ 'bg-brand-950 mask-contain mask-center mask-no-repeat': !isDark }"
                            :style="isDark ? undefined : { maskImage: `url(${fullLogo})` }"
                        >
                            <img :src="fullLogo" alt="FullParty" class="h-10 w-auto object-contain" :class="{ 'opacity-0': !isDark }">
                        </span>
                    </span>
                </template>

                <template #right>
                    <TimeDisplayModeToggle v-if="user" />
                    <AppearanceToggle />
                    <NotificationBell v-if="user" />
                    <div class="hidden sm:inline-flex">
                        <AppLocaleSelect variant="ghost" />
                    </div>
                    <div class="inline-flex sm:hidden">
                        <AppLocaleSelect variant="ghost" compact />
                    </div>
                    <UserMenuV2 />
                </template>
            </UHeader>

            <main id="main-content" class="bg-elevation-2 dark:bg-elevation-1 min-w-0 flex-1 p-4 sm:p-6 lg:p-8">
                <slot />
            </main>
        </div>
    </UApp>
</template>
