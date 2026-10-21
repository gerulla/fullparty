<script setup lang="ts">
import { computed } from 'vue'
import RunCard from '@/components/Home/RunCard.vue'
import type { V2HomeRunCard } from '../../Types/V2Home'

const props = withDefaults(defineProps<{
    runs: V2HomeRunCard[]
    label: string
    rows?: 1 | 2
}>(), { rows: 1 })

const columns = computed(() => Array.from(
    { length: Math.ceil(props.runs.length / props.rows) },
    (_, index) => props.runs.slice(index * props.rows, (index + 1) * props.rows),
))
</script>

<template>
    <UCarousel :key="rows" v-slot="{ item }" :items="columns" align="start" arrows drag-free
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
        <div v-if="rows === 2" class="grid h-full grid-rows-2 gap-6">
            <RunCard v-for="run in item" :key="run.id" :run="run" />
        </div>
        <RunCard v-else :key="item[0].id" :run="item[0]" />
    </UCarousel>
</template>
