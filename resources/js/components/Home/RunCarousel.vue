<script setup lang="ts">
import RunCard from '@/components/Home/RunCard.vue'
import type { V2HomeRunCard } from '../../Types/V2Home'

defineProps<{
    runs: V2HomeRunCard[]
    label: string
}>()
</script>

<template>
    <UCarousel v-slot="{ item }" :items="runs" align="start" arrows drag-free
        :watch-drag="true" :aria-label="label"
        :prev="{ color: 'neutral', variant: 'soft' }" :next="{ color: 'neutral', variant: 'soft' }"
        :ui="{
            item: 'basis-[19rem] max-w-full',
            viewport: 'p-1 -m-1 select-none touch-pan-y cursor-grab active:cursor-grabbing',
            controls: 'absolute right-0 -top-12',
            arrows: 'flex items-center gap-2',
            prev: 'static translate-y-0 rounded-md bg-elevation-1 hover:bg-elevation-2 dark:bg-elevation-3 dark:hover:bg-elevation-5',
            next: 'static translate-y-0 rounded-md bg-elevation-1 hover:bg-elevation-2 dark:bg-elevation-3 dark:hover:bg-elevation-5',
        }">
        <RunCard :key="item.id" :run="item" />
    </UCarousel>
</template>
