<script setup lang="ts">
import type { HolsterLoadout } from '@/Types/HolsterPlanner'
import HolsterLoadoutPanel from './HolsterLoadoutPanel.vue'

defineProps<{ prepop?: HolsterLoadout | null; refills: HolsterLoadout[]; compact?: boolean }>()
</script>

<template>
    <div class="grid min-w-0 gap-5" :class="{ 'sm:grid-cols-2': prepop && refills.length }">
        <HolsterLoadoutPanel v-if="prepop" :holster="prepop" :compact="compact" />
        <div v-if="refills.length" class="min-w-0 space-y-5">
            <HolsterLoadoutPanel v-for="refill in refills" :key="refill.id" :holster="refill" :compact="compact" />
        </div>
    </div>
</template>
