<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ResourceHolsterLoadout } from '@/Types/GroupResources'
defineProps<{ holster: ResourceHolsterLoadout }>()
const { t } = useI18n()
</script>

<template>
    <section id="resource-holster-loadout" tabindex="-1" class="border border-default p-4 sm:p-5">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="flex items-center gap-2 text-lg font-semibold text-highlighted"><UIcon name="i-lucide-backpack" class="size-5" />{{ t('groups.resources.holsters.loadout') }}</h2>
            <span class="text-sm text-muted">{{ t('groups.resources.holsters.capacity', { used: holster.capacity_used, max: holster.max_capacity }) }}</span>
        </header>
        <div v-if="holster.items.length" class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            <div v-for="item in holster.items" :key="item.id" class="flex min-w-0 items-center gap-3">
                <img v-if="item.icon_url" :src="item.icon_url" alt="" class="size-10 shrink-0 object-contain" />
                <UIcon v-else name="i-lucide-package" class="m-2 size-6 shrink-0 text-muted" />
                <span class="min-w-0 flex-1 text-sm">{{ item.name }}</span>
                <span class="text-sm tabular-nums text-muted">×{{ item.quantity }}</span>
            </div>
        </div>
        <p v-else class="text-sm text-muted">{{ t('groups.resources.holsters.no_items') }}</p>
    </section>
</template>
