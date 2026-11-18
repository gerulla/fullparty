<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import AppLocaleSelect from '@/components/Navigation/AppLocaleSelect.vue'
import SearchableTopbar from '@/components/Navigation/SearchableTopbar.vue'
import AppearanceToggle from '@/components/Navigation/AppearanceToggle.vue'
import NotificationBell from '@/components/Navigation/NotificationBell.vue'
import TimeDisplayModeToggle from '@/components/Navigation/TimeDisplayModeToggle.vue'
import UserMenuV2 from '@/components/Navigation/UserMenuV2.vue'
import V2Footer from '@/components/Navigation/V2Footer.vue'
import AccountFlags from '@/components/Navigation/AccountFlags.vue'
import GroupSwitcher from '@/components/Groups/GroupSwitcher.vue'
import GroupMobileNavigation from '@/components/Groups/GroupMobileNavigation.vue'
import SystemBanner from '@/components/SystemBanner.vue'
import type { GroupDashboardHeaderGroup } from '@/Types/GroupNavigation'
import DevelopmentPreviewHost from '@/components/Shared/DevelopmentPreviewHost.vue'
import { usePersistentLocale } from '@/composables/usePersistentLocale'
import { useUiTheme } from '@/composables/useUiTheme'
import { provideDevelopmentPreviewMenu } from '@/composables/useDevelopmentPreviewMenu'

useUiTheme('medas')
provideDevelopmentPreviewMenu()

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)
const { currentUiLocale } = usePersistentLocale()
const breakpoints = useBreakpoints(breakpointsTailwind)
const isMobile = breakpoints.smaller('md')
const isMobileOrTablet = breakpoints.smaller('xl')
const isSmallDesktop = breakpoints.between('xl', '2xl')
const compactAccountMenu = breakpoints.smaller('2xl')
const dashboardGroup = computed<GroupDashboardHeaderGroup | null>(() =>
    page.component.startsWith('Dashboard/Groups/') && page.props.group_dashboard
        ? page.props.group_dashboard as GroupDashboardHeaderGroup
        : null,
)
const isMobileGroup = computed(() => Boolean(dashboardGroup.value) && isMobile.value)
const isResourceWorkspace = computed(() => page.component === 'Dashboard/Groups/Resources/Manage')
const isGroupHomepage = computed(() => page.component === 'Dashboard/Groups/CommunityDashboard')
</script>

<template>
    <UApp :locale="currentUiLocale" class="font-inter">
        <div class="flex min-h-dvh flex-col text-default font-inter">
            <SystemBanner v-if="dashboardGroup && page.props.system_banner" :banner="page.props.system_banner" />
            <SearchableTopbar>
                <template #leading>
                    <GroupMobileNavigation v-if="isMobileGroup && dashboardGroup" :group="dashboardGroup" />
                </template>
                <template #context>
                    <GroupSwitcher v-if="dashboardGroup && !isMobile" :group="dashboardGroup" />
                    <DevelopmentPreviewHost v-if="page.props.theme_preview_enabled === true" />
                </template>
                <template #actions>
                    <TimeDisplayModeToggle v-if="user && !isMobileOrTablet" :compact="isSmallDesktop" />
                    <AppearanceToggle v-if="!isMobileOrTablet" />
                    <NotificationBell v-if="user" :touch-target="isMobileOrTablet" />
                    <AppLocaleSelect v-if="!isMobileOrTablet" variant="ghost" :flag-only="isSmallDesktop" />
                    <UserMenuV2 :compact="compactAccountMenu" :mobile="isMobileOrTablet" />
                </template>
            </SearchableTopbar>
            <AccountFlags v-if="user" />

            <main id="main-content" class="min-w-0 flex-1 bg-elevation-2 dark:bg-elevation-1"
                :class="dashboardGroup ? 'p-0' : 'p-4 sm:p-6 lg:p-8'">
                <slot v-if="dashboardGroup" name="group-header" />
                <div v-if="dashboardGroup" class="[&_[data-page-header]:first-child]:pt-0"
                    :class="isResourceWorkspace ? 'lg:flex lg:h-[calc(100dvh-var(--ui-header-height))] lg:flex-col lg:overflow-hidden' : isGroupHomepage ? '' : 'p-4 sm:p-6 lg:p-8'">
                    <slot />
                </div>
                <slot v-else />
            </main>
            <V2Footer />
        </div>
    </UApp>
</template>
