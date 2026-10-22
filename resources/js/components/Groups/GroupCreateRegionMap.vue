<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { DatacenterRegion } from '@/Types/Groups'
import mapPaths from '@/assets/maps/datacenter-regions.json'

defineProps<{ modelValue: DatacenterRegion }>()
const emit = defineEmits<{ 'update:modelValue': [region: DatacenterRegion] }>()
const { t } = useI18n()
const regions = [
    { value: 'NA', left: '23%', top: '30%', labelX: 212, labelY: 108 },
    { value: 'EU', left: '52%', top: '23%', labelX: 557, labelY: 100 },
    { value: 'JP', left: '88%', top: '34%', labelX: 927, labelY: 141 },
    { value: 'OCE', left: '87%', top: '79%', labelX: 870, labelY: 302 },
] as const
</script>

<template>
    <fieldset class="min-w-0">
        <legend class="sr-only">{{ t('groups.index.create_flow.datacenter.region_label') }}</legend>
        <div class="relative isolate w-full">
            <img :src="'/images/groups/world-map.svg'" alt="" width="1000" height="440"
                class="block h-auto w-full opacity-60 xl:hidden dark:opacity-30" draggable="false" />
            <svg viewBox="0 0 1000 400" role="group" :aria-label="t('groups.index.create_flow.datacenter.region_label')"
                class="hidden h-auto w-full overflow-visible xl:block">
                <image :href="'/images/groups/world-map.svg'" width="1000" height="440"
                    class="pointer-events-none opacity-30 dark:opacity-20" aria-hidden="true" />
                <g v-for="region in regions" :key="region.value" role="button" tabindex="0"
                    :aria-label="t(`groups.index.create_flow.datacenter.regions.${region.value}`)"
                    :aria-pressed="modelValue === region.value"
                    class="map-region group cursor-pointer outline-none"
                    :class="modelValue === region.value ? 'text-brand-700 dark:text-brand-400' : 'text-neutral-400 dark:text-neutral-500'"
                    @click="emit('update:modelValue', region.value)"
                    @keydown.enter.prevent="emit('update:modelValue', region.value)"
                    @keydown.space.prevent="emit('update:modelValue', region.value)">
                    <title>{{ t(`groups.index.create_flow.datacenter.regions.${region.value}`) }}</title>
                    <path :d="mapPaths.regions[region.value]" class="map-region-land pointer-events-none fill-current stroke-current transition-colors motion-reduce:transition-none"
                        :class="modelValue === region.value ? 'group-hover:text-brand-600 dark:group-hover:text-brand-300' : 'group-hover:text-brand-500 dark:group-hover:text-brand-300'"
                        stroke-width="0.7" stroke-linejoin="round" />
                    <!-- A small invisible coastal margin makes islands easier to click. -->
                    <path :d="mapPaths.regions[region.value]" fill="transparent" stroke="transparent"
                        :stroke-width="region.value === 'JP' ? 18 : 4" vector-effect="non-scaling-stroke" pointer-events="all" />
                    <path v-if="region.value === 'JP'" d="M899 142H912" class="pointer-events-none stroke-current" fill="none" />
                    <text :x="region.labelX" :y="region.labelY" text-anchor="middle" dominant-baseline="middle"
                        class="text-base font-semibold tracking-wider"
                        :class="region.value === 'JP'
                            ? modelValue === region.value ? 'fill-brand-700 dark:fill-brand-300' : 'fill-neutral-600 dark:fill-neutral-300'
                            : modelValue === region.value ? 'fill-white dark:fill-neutral-950' : 'fill-neutral-900 dark:fill-white'"
                        aria-hidden="true">{{ region.value }}</text>
                </g>
            </svg>
            <UButton v-for="region in regions" :key="region.value" type="button"
                :color="modelValue === region.value ? 'brand' : 'neutral'"
                :variant="modelValue === region.value ? 'solid' : 'outline'"
                :aria-label="t(`groups.index.create_flow.datacenter.regions.${region.value}`)"
                :aria-pressed="modelValue === region.value"
                :style="{ left: region.left, top: region.top }"
                class="absolute min-h-11 min-w-14 -translate-x-1/2 -translate-y-1/2 justify-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold shadow-sm sm:min-w-20 sm:px-3 sm:text-sm xl:hidden"
                :class="modelValue === region.value
                    ? 'bg-brand-700 text-white ring-2 ring-brand-700/20 ring-offset-2 ring-offset-elevation-2 hover:bg-brand-600 dark:bg-brand-300 dark:text-neutral-950 dark:ring-brand-300/30 dark:hover:bg-brand-200'
                    : 'bg-elevation-1/95 text-muted ring-elevation-4 hover:text-highlighted dark:bg-elevation-4/95 dark:ring-elevation-7'"
                @click="emit('update:modelValue', region.value)">
                <span class="size-1.5 rounded-full bg-current" aria-hidden="true" />
                {{ region.value }}
            </UButton>
        </div>
    </fieldset>
</template>

<style scoped>
.map-region:focus-visible .map-region-land {
    filter: drop-shadow(0 0 3px var(--color-brand-400));
    stroke-width: 2;
}
</style>
