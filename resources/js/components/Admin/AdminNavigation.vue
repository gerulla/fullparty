<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useAdminNavigation } from '@/composables/useAdminNavigation'

const { t } = useI18n()
const { overview, sections, current, mobileItems } = useAdminNavigation()
</script>

<template>
    <nav :aria-label="t('navigation.admin_panel.title')" class="min-w-0 xl:sticky xl:top-[calc(var(--ui-header-height)+1rem)]">
        <UDropdownMenu :items="mobileItems" :content="{ align: 'start', collisionPadding: 12 }"
            :ui="{ content: 'admin-theme w-(--reka-dropdown-menu-trigger-width) max-w-[calc(100vw-2rem)] max-h-[65dvh] overflow-y-auto rounded-xl bg-elevation-1 shadow-lg ring-default dark:bg-elevation-4' }">
            <button type="button" class="admin-panel flex w-full items-center gap-2.5 px-3.5 py-3 text-sm font-medium text-highlighted focus-visible:outline-2 focus-visible:outline-primary sm:w-auto xl:hidden">
                <UIcon name="i-lucide-panels-top-left" class="size-4 shrink-0 text-primary" />
                <span>{{ t('navigation.admin_panel.title') }}</span>
                <span aria-hidden="true" class="text-dimmed">/</span>
                <span class="min-w-0 truncate text-toned">{{ current.label }}</span>
                <UIcon name="i-lucide-chevrons-up-down" class="ms-auto size-4 shrink-0 text-muted" />
            </button>
        </UDropdownMenu>
        <div class="admin-panel hidden max-h-[calc(100dvh-var(--ui-header-height)-2rem)] overflow-y-auto p-3 xl:block">
            <Link :href="overview.to" :aria-label="t('navigation.admin_panel.title') + ' · ' + overview.label" class="flex items-center gap-2.5 rounded-lg px-2 py-2.5 text-sm font-semibold text-highlighted hover:bg-muted focus-visible:outline-2 focus-visible:outline-primary">
                <UIcon name="i-lucide-shield-check" class="size-5 shrink-0 text-primary" />{{ t('navigation.admin_panel.title') }}
            </Link>
            <ul class="mt-2 space-y-1 border-t border-muted pt-2">
                <li v-for="section in sections" :key="section.label">
                    <p v-if="section.links.length > 1" class="px-2.5 pt-3 pb-1 text-xs font-semibold text-muted">{{ section.label }}</p>
                    <Link v-for="item in section.links" :key="item.to" :href="item.to" :aria-current="item.active ? 'page' : undefined"
                        class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm leading-tight focus-visible:outline-2 focus-visible:outline-primary"
                        :class="item.active ? 'bg-primary/10 font-semibold text-primary' : 'text-toned hover:bg-muted hover:text-highlighted'">
                        <UIcon :name="item.icon" class="size-4 shrink-0" />
                        {{ item.label }}
                    </Link>
                </li>
            </ul>
        </div>
    </nav>
</template>
