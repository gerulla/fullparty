<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useAdminNavigation } from '@/composables/useAdminNavigation'

const { t } = useI18n()
const { items, mobileItems, current } = useAdminNavigation()
const navigationUi = {
    root: 'relative z-50 !overflow-visible',
    list: 'flex-wrap !overflow-visible',
    viewportWrapper: 'z-50 !overflow-visible',
    viewport: 'z-50 !overflow-visible rounded-none border border-default bg-elevated/95 shadow-xl backdrop-blur-xl ring-0',
    content: 'w-64 !max-h-none !overflow-visible',
    childList: 'gap-1 p-1.5',
    childLink: 'rounded-none',
}
</script>

<template>
    <UDashboardToolbar class="relative z-40 flex-wrap gap-y-0.5 py-1 !overflow-visible">
        <UNavigationMenu :items="items" :aria-label="t('navigation.admin_panel.title')" variant="link" color="brand"
            highlight-color="brand" content-orientation="vertical" highlight :ui="navigationUi" class="hidden min-w-0 flex-1 lg:flex" />
        <UDropdownMenu :items="mobileItems" :content="{ align: 'start' }"
            :ui="{ content: 'w-80 max-w-[calc(100vw-2rem)] max-h-[70dvh] overflow-y-auto rounded-none' }">
            <UButton color="neutral" variant="ghost" :icon="current.icon" trailing-icon="i-lucide-chevron-down"
                :aria-label="t('navigation.admin_panel.title')" class="w-full justify-between lg:hidden" :label="current.label" />
        </UDropdownMenu>
    </UDashboardToolbar>
</template>
