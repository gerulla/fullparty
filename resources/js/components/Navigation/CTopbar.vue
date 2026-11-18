<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import AppLocaleSelect from '@/components/Navigation/AppLocaleSelect.vue'
import NotificationBell from '@/components/Navigation/NotificationBell.vue'
import SearchableTopbar from '@/components/Navigation/SearchableTopbar.vue'
import TimeDisplayModeToggle from '@/components/Navigation/TimeDisplayModeToggle.vue'
import UserMenu from '@/components/Navigation/UserMenu.vue'
import UserMenuV2 from '@/components/Navigation/UserMenuV2.vue'

defineProps<{ title?: string }>()
const page = usePage()
const { t } = useI18n()
const user = computed(() => page.props.auth?.user ?? null)
const compactActions = useBreakpoints(breakpointsTailwind).smaller('xl')
</script>

<template>
    <SearchableTopbar :desktop-logo="false">
        <template #leading>
            <UDashboardSidebarToggle />
            <UDashboardSidebarCollapse />
        </template>
        <template #context>
            <h1 v-if="page.component === 'Profile/Show'" class="sr-only md:not-sr-only md:truncate md:font-semibold">
                {{ t('navigation.topbar.menu.profile') }}
            </h1>
        </template>
        <template #actions>
            <TimeDisplayModeToggle v-if="user && !compactActions" compact />
            <NotificationBell v-if="user" :touch-target="compactActions" />
            <AppLocaleSelect v-if="!compactActions" variant="ghost" flag-only />
            <UserMenuV2 v-if="compactActions" compact mobile />
            <UserMenu v-else />
        </template>
    </SearchableTopbar>
</template>
