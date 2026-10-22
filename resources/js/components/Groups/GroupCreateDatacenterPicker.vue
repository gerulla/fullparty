<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import GroupCreateRegionMap from '@/components/Groups/GroupCreateRegionMap.vue'
import type { DatacenterRegion, GroupDatacenterOption } from '@/Types/Groups'

const props = defineProps<{
    region: DatacenterRegion
    modelValue: string | null
    datacenters: GroupDatacenterOption[]
}>()
const emit = defineEmits<{
    'update:region': [region: DatacenterRegion]
    'update:modelValue': [datacenter: string]
    continue: []
}>()
const { t } = useI18n()
const regionalDatacenters = computed(() => props.datacenters.filter(dc => dc.region === props.region))
const selected = computed(() => regionalDatacenters.value.find(dc => dc.value === props.modelValue))
</script>

<template>
    <div class="flex flex-col gap-2">
        <GroupCreateRegionMap :model-value="region" @update:model-value="emit('update:region', $event)" />
        <fieldset class="min-w-0">
            <legend class="mb-6 w-full text-center">
                <span class="block text-lg font-semibold text-highlighted">{{ t(`groups.index.create_flow.datacenter.regions.${region}`) }}</span>
            </legend>
            <div class="mx-auto grid gap-3 sm:gap-4"
                :class="regionalDatacenters.length === 1 ? 'max-w-xs grid-cols-1' : regionalDatacenters.length === 2 ? 'max-w-xl grid-cols-2' : 'grid-cols-2 md:grid-cols-4'">
                <UCard v-for="dc in regionalDatacenters" :key="dc.value" as="button" type="button"
                    :aria-label="dc.label" :aria-pressed="modelValue === dc.value"
                    class="min-w-0 cursor-pointer rounded-xl border bg-elevation-1 text-left shadow-sm ring-0 transition-colors focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand motion-reduce:transition-none dark:bg-elevation-3 dark:shadow-none"
                    :class="modelValue === dc.value
                        ? 'border-brand-600 ring-1 ring-brand-600 dark:border-brand-300 dark:ring-brand-300'
                        : 'border-elevation-4 hover:border-brand-500 dark:border-elevation-6 dark:hover:border-brand-400'"
                    :ui="{ body: 'p-4 sm:p-5' }"
                    @click="emit('update:modelValue', dc.value)">
                    <div class="mb-3 flex items-center justify-between gap-2 border-b pb-3"
                        :class="modelValue === dc.value ? 'border-brand-500/30' : 'border-elevation-3 dark:border-elevation-6'">
                        <h3 class="text-base font-semibold sm:text-lg"
                            :class="modelValue === dc.value ? 'text-brand-700 dark:text-brand-300' : 'text-highlighted'">{{ dc.label }}</h3>
                        <UIcon :name="modelValue === dc.value ? 'i-lucide-circle-check' : 'i-lucide-circle'"
                            class="size-4 shrink-0"
                            :class="modelValue === dc.value ? 'text-brand-700 dark:text-brand-300' : 'text-dimmed'" aria-hidden="true" />
                    </div>
                    <ul class="space-y-1 text-xs leading-relaxed text-muted sm:text-sm">
                        <li v-for="world in dc.worlds" :key="world">{{ world }}</li>
                    </ul>
                </UCard>
            </div>
        </fieldset>
        <div class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-sm text-muted" aria-live="polite">
                {{ selected ? t('groups.index.create_flow.datacenter.selected', { datacenter: selected.label }) : t('groups.index.create_flow.datacenter.select_prompt') }}
            </p>
            <UButton type="button" color="brand" trailing-icon="i-lucide-arrow-right" :disabled="!selected"
                class="w-full justify-center rounded-lg sm:w-auto" @click="emit('continue')">
                {{ t('general.continue') }}
            </UButton>
        </div>
    </div>
</template>
