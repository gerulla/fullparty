<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { breakpointsTailwind, onClickOutside, useBreakpoints } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import AppLocaleSelect from '@/components/Navigation/AppLocaleSelect.vue'
import GlobalSearchBox from '@/components/Navigation/GlobalSearchBox.vue'
import AppearanceToggle from '@/components/Navigation/AppearanceToggle.vue'
import NotificationBell from '@/components/Navigation/NotificationBell.vue'
import TimeDisplayModeToggle from '@/components/Navigation/TimeDisplayModeToggle.vue'
import UserMenuV2 from '@/components/Navigation/UserMenuV2.vue'
import V2Footer from '@/components/Navigation/V2Footer.vue'
import AccountFlags from '@/components/Navigation/AccountFlags.vue'
import GroupDashboardNavigation from '@/components/Groups/GroupDashboardNavigation.vue'
import SystemBanner from '@/components/SystemBanner.vue'
import type { GroupNavigationContext } from '@/Types/GroupNavigation'
import DevelopmentPreviewHost from '@/components/Shared/DevelopmentPreviewHost.vue'
import { useAppearance } from '@/composables/useAppearance'
import { usePersistentLocale } from '@/composables/usePersistentLocale'
import { useUiTheme } from '@/composables/useUiTheme'
import { provideDevelopmentPreviewMenu } from '@/composables/useDevelopmentPreviewMenu'

useUiTheme('medas')
provideDevelopmentPreviewMenu()

const page = usePage()
const { t } = useI18n()
const user = computed(() => page.props.auth?.user ?? null)
const { isDark } = useAppearance()
const { currentLocale, currentUiLocale } = usePersistentLocale()
const compactLogo = '/logos/compact.png'
const fullLogo = '/logos/full.png'
const breakpoints = useBreakpoints(breakpointsTailwind)
const isMobileOrTablet = breakpoints.smaller('xl')
const isSmallDesktop = breakpoints.between('xl', '2xl')
const hasCompactSearch = breakpoints.smaller('2xl')
const searchOpen = ref(false)
const searchRegion = ref<HTMLElement | null>(null)
const searchId = useId()
const groupMenuOpen = ref(false)
const dashboardGroup = computed<GroupNavigationContext | null>(() =>
    page.component.startsWith('Dashboard/Groups/') && page.props.group
        ? page.props.group as GroupNavigationContext
        : null,
)
const isResourceWorkspace = computed(() => page.component === 'Dashboard/Groups/Resources/Manage')
const isGroupHomepage = computed(() => page.component === 'Dashboard/Groups/CommunityDashboard')

watch([isMobileOrTablet, () => page.url], () => { groupMenuOpen.value = false })

async function closeSearch(restoreFocus = true) {
    if (!searchOpen.value) return
    searchOpen.value = false
    if (restoreFocus) {
        await nextTick()
        searchRegion.value?.querySelector<HTMLButtonElement>('[data-compact-search-trigger]')?.focus()
    }
}

function handleSearchFocusOut(event: FocusEvent) {
    if (event.relatedTarget instanceof Node && !searchRegion.value?.contains(event.relatedTarget)) {
        void closeSearch(false)
    }
}

onClickOutside(searchRegion, () => { void closeSearch(false) })
watch([isMobileOrTablet, hasCompactSearch, () => page.url], () => { void closeSearch(false) })
</script>

<template>
    <UApp :locale="currentUiLocale" class="font-inter">
        <div class="flex min-h-dvh flex-col text-default font-inter">
            <SystemBanner v-if="dashboardGroup && page.props.system_banner" :banner="page.props.system_banner" />
            <UHeader
                data-account-flags-header
                title="FullParty"
                :to="route('dashboard', { locale: currentLocale })"
                :toggle="false"
                class="h-auto min-h-(--ui-header-height) bg-elevation-1 dark:bg-elevation-2"
                :ui="{
                    container: 'max-w-none min-h-(--ui-header-height) flex-nowrap px-4 py-2 sm:px-6 lg:px-8 xl:py-0',
                    left: isMobileOrTablet && searchOpen ? 'hidden' : 'min-w-0 flex-1 xl:flex-none 2xl:flex-1',
                    center: 'hidden min-w-0 justify-center 2xl:flex 2xl:flex-1',
                    right: 'min-w-0 flex-1 gap-1 sm:gap-1.5',
                }"
            >
                <GlobalSearchBox v-if="user && !hasCompactSearch" align="center" class="w-full max-w-lg" />
                <template #left>
                    <USlideover v-if="dashboardGroup && isMobileOrTablet" v-model:open="groupMenuOpen" side="left"
                        :title="t('groups.index.navigation.menu')"
                        :ui="{ content: 'w-80 max-w-[calc(100vw-2rem)] bg-elevation-1 dark:bg-elevation-2 font-inter', header: 'min-h-16 px-4', body: 'p-4 sm:p-4' }">
                        <UButton color="neutral" variant="ghost" icon="i-lucide-menu"
                            class="size-11 shrink-0 justify-center rounded-md hover:bg-elevated"
                            :aria-label="t('groups.index.navigation.menu')" />
                        <template #body>
                            <GroupDashboardNavigation :group="dashboardGroup" @navigate="groupMenuOpen = false" />
                        </template>
                    </USlideover>
                    <Link :href="route('dashboard', { locale: currentLocale })" aria-label="FullParty" class="min-w-0 shrink-0">
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
                    </Link>
                    <DevelopmentPreviewHost v-if="page.props.theme_preview_enabled === true" />
                </template>

                <template #right>
                    <div ref="searchRegion" class="flex w-full min-w-0 items-center justify-end gap-1 sm:gap-1.5"
                        @keydown.esc="closeSearch()" @focusout="handleSearchFocusOut">
                        <Transition enter-active-class="transition duration-200 ease-out origin-right motion-reduce:transition-none"
                            enter-from-class="translate-x-4 scale-x-95 opacity-0" enter-to-class="translate-x-0 scale-x-100 opacity-100">
                            <div v-if="user && hasCompactSearch && searchOpen" :id="searchId" class="flex w-full items-center gap-2 xl:max-w-2xl">
                                <GlobalSearchBox autofocus :align="isMobileOrTablet ? 'start' : 'end'" class="min-w-0 flex-1" @selected="closeSearch(false)" />
                                <UButton type="button" color="neutral" variant="ghost" icon="i-lucide-x"
                                    class="shrink-0 rounded-md hover:bg-elevated max-xl:size-11 max-xl:justify-center" :aria-label="t('general.close')" @click="closeSearch()" />
                            </div>
                        </Transition>
                        <div v-show="!searchOpen" class="flex items-center gap-1 sm:gap-1.5">
                            <UButton v-if="user && hasCompactSearch" data-compact-search-trigger type="button" color="neutral" variant="ghost"
                                icon="i-lucide-search" class="rounded-md hover:bg-elevated max-xl:size-11 max-xl:justify-center" :aria-label="t('navigation.topbar.search')"
                                :aria-expanded="searchOpen" :aria-controls="searchId" @click="searchOpen = true" />
                            <TimeDisplayModeToggle v-if="user && !isMobileOrTablet" :compact="isSmallDesktop" />
                            <AppearanceToggle v-if="!isMobileOrTablet" />
                            <NotificationBell v-if="user" :touch-target="isMobileOrTablet" />
                            <div v-if="!isMobileOrTablet" class="inline-flex">
                                <AppLocaleSelect variant="ghost" :flag-only="isSmallDesktop" />
                            </div>
                            <UserMenuV2 :compact="hasCompactSearch" :mobile="isMobileOrTablet" />
                        </div>
                    </div>
                </template>
            </UHeader>
            <AccountFlags v-if="user" />

            <div class="flex min-w-0 flex-1">
                <aside v-if="dashboardGroup" class="hidden w-64 shrink-0 border-r border-muted bg-elevation-1 dark:bg-elevation-2 xl:block">
                    <div class="sticky top-(--ui-header-height) max-h-[calc(100dvh-var(--ui-header-height))] overflow-y-auto overscroll-contain p-4">
                        <GroupDashboardNavigation :group="dashboardGroup" />
                    </div>
                </aside>
                <div class="flex min-w-0 flex-1 flex-col">
                    <main id="main-content" class="min-w-0 flex-1 bg-elevation-2 dark:bg-elevation-1"
                        :class="isResourceWorkspace ? 'p-0 lg:flex lg:h-[calc(100dvh-var(--ui-header-height))] lg:flex-none lg:flex-col lg:overflow-hidden' : isGroupHomepage ? 'p-0' : 'p-4 sm:p-6 lg:p-8'">
                        <slot />
                    </main>
                    <V2Footer />
                </div>
            </div>
        </div>
    </UApp>
</template>
