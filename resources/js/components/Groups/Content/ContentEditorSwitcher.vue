<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { router } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const props = defineProps<{ groupSlug: string; content: 'drs' | 'forked_tower' }>()
const { t, locale } = useI18n()
const options = computed(() => [
    {
        value: 'drs',
        label: t('groups.index.content.delubrum_reginae_savage.title'),
        icon: 'i-lucide-shield',
        href: route('groups.dashboard.content.index', { group: props.groupSlug, locale: locale.value, type: 'drs' }),
    },
    {
        value: 'forked_tower',
        label: t('groups.index.content.overview.forked_tower_title'),
        icon: 'i-lucide-sparkles',
        href: route('groups.dashboard.content.index', { group: props.groupSlug, locale: locale.value, type: 'forkedtower' }),
    },
])
const title = computed(() => options.value.find(option => option.value === props.content)?.label)
const items = computed<DropdownMenuItem[][]>(() => [
    options.value.map(option => ({
        label: option.label,
        icon: option.value === props.content ? 'i-lucide-check' : option.icon,
        disabled: option.value === props.content,
        onSelect: () => router.visit(option.href, { preserveState: true, preserveScroll: true }),
    })),
    [{
        label: t('groups.dashboard.navigation.content_customization'),
        icon: 'i-lucide-layout-grid',
        onSelect: () => router.visit(route('groups.dashboard.content.index', { group: props.groupSlug, locale: locale.value }), { preserveState: true, preserveScroll: true }),
    }],
])
</script>

<template>
    <div class="w-full min-w-0">
        <h1 class="sr-only">{{ title }}</h1>
        <UDropdownMenu :items="items" :content="{ align: 'center', sideOffset: 6, collisionPadding: 12 }"
            :ui="{
                content: 'w-(--reka-dropdown-menu-trigger-width) max-w-[calc(100vw-1.5rem)] rounded-lg bg-elevation-1 font-inter shadow-lg ring-default',
                item: 'min-h-11 cursor-pointer gap-3 rounded-md px-3 py-2 text-default before:hidden data-highlighted:bg-elevation-3',
                itemLabel: 'whitespace-normal',
            }">
            <UButton color="neutral" variant="ghost" trailing-icon="i-lucide-chevron-down"
                class="w-full justify-center gap-2 rounded-lg px-2 py-2 text-lg font-semibold text-highlighted hover:bg-elevated data-[state=open]:bg-elevated"
                :aria-label="`${t('groups.index.content.delubrum_reginae_savage.holsters.workspace.change_content')}: ${title}`">
                <span class="min-w-0 whitespace-normal text-center">{{ title }}</span>
            </UButton>
        </UDropdownMenu>
    </div>
</template>
