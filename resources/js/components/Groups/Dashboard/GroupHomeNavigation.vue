<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { GroupHomeNavigationItem } from '@/Types/GroupNavigation'

defineProps<{ items: GroupHomeNavigationItem[] }>()
const { t } = useI18n()
const dropdownUi = {
    content: 'w-80 max-w-[calc(100vw-1.5rem)] rounded-lg bg-elevation-1 font-inter shadow-lg ring-default',
    viewport: 'p-1.5',
    item: 'gap-3 rounded-md px-3 py-3 cursor-pointer text-default data-highlighted:bg-elevation-3 before:hidden',
    itemLeadingIcon: 'size-5 shrink-0 text-muted',
    itemLabel: 'flex flex-wrap items-center gap-x-2 gap-y-1 font-semibold text-highlighted',
    itemDescription: 'mt-0.5 whitespace-normal text-xs leading-5 text-muted',
}
</script>

<template>
    <nav :aria-label="t('groups.index.navigation.menu')" class="hidden border-b border-default bg-default px-4 sm:px-6 md:block lg:px-8">
        <div class="flex min-w-0 items-center gap-1 overflow-x-auto py-1">
            <template v-for="item in items" :key="item.key">
                <UDropdownMenu v-if="item.links?.length" :items="item.links" :modal="false"
                    :content="{ align: 'start', sideOffset: 6, collisionPadding: 12 }" :ui="dropdownUi">
                    <UButton :label="item.label" :icon="item.icon" color="neutral" variant="ghost"
                        trailing-icon="i-lucide-chevron-down"
                        class="shrink-0 rounded-md px-3 py-3 text-sm text-default hover:bg-elevated data-[state=open]:bg-elevated [&[data-state=open]>[data-slot=trailingIcon]]:rotate-180"
                        :class="{ 'text-brand': item.active }"
                    />
                    <template #item-label="{ item: link }">
                        <span>{{ link.label }}</span>
                        <UBadge v-if="link.badge" :label="link.badge" color="neutral" variant="soft" size="xs" class="rounded-full" />
                    </template>
                    <template #item-trailing>
                        <UIcon name="i-lucide-chevron-right" class="size-4 text-muted" aria-hidden="true" />
                    </template>
                </UDropdownMenu>
                <UButton v-else :href="item.href" :label="item.label" :icon="item.icon" color="neutral" variant="ghost"
                    :aria-current="item.active ? 'page' : undefined"
                    class="relative shrink-0 rounded-md px-3 py-3 text-sm text-default hover:bg-elevated"
                    :class="item.active ? 'font-semibold text-highlighted after:absolute after:-bottom-1 after:inset-x-2 after:h-0.5 after:bg-brand' : ''"
                />
            </template>
        </div>
    </nav>
</template>
