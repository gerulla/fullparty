<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ResourceHolsterLoadout } from '@/Types/GroupResources'
import HolsterPairPanel from '@/components/Shared/Holsters/HolsterPairPanel.vue'
defineProps<{ holster: ResourceHolsterLoadout }>()
const { t } = useI18n()
</script>

<template>
    <section id="resource-holster-loadout" tabindex="-1" class="rounded-xl border border-elevation-3 bg-elevation-1 p-4 shadow-sm dark:border-elevation-6 dark:bg-elevation-3 dark:shadow-none sm:p-5">
        <header class="mb-5">
            <h2 class="flex items-center gap-2 text-lg font-semibold text-highlighted"><UIcon name="i-lucide-backpack" class="size-5" />{{ t('groups.resources.holsters.loadout') }}</h2>
        </header>
        <HolsterPairPanel :prepop="holster.prepop" :refills="holster.refills" />
    </section>
</template>
