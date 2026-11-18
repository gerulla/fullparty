<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBreakpoints, breakpointsTailwind } from '@vueuse/core'
import { parseDate, today } from '@internationalized/date'
import type { DateRange } from 'reka-ui'
import type { RunSearchDates } from '@/Types/RunDiscovery'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'

const props = withDefaults(defineProps<{ modelValue: RunSearchDates; showDone?: boolean }>(), { showDone: true })
const emit = defineEmits<{ 'update:modelValue': [value: RunSearchDates]; done: [] }>()
const { t } = useI18n()
const { displayTimeZone, displayTimeZoneLabel } = useDateTimeFormat()
const twoMonths = useBreakpoints(breakpointsTailwind).greaterOrEqual('md')
const currentDate = computed(() => today(displayTimeZone.value ?? Intl.DateTimeFormat().resolvedOptions().timeZone))
const range = computed(() => ({
    start: props.modelValue.from ? parseDate(props.modelValue.from) : undefined,
    end: props.modelValue.to ? parseDate(props.modelValue.to) : undefined,
}))
const tabs = computed(() => ['dates', 'flexible'].map(value => ({ value, label: t(`runs.search.${value}`) })))
function update(change: Partial<RunSearchDates>) { emit('update:modelValue', { ...props.modelValue, ...change }) }
function updateRange(value: DateRange | undefined) {
    update({ from: value?.start?.toString() ?? null, to: value?.end?.toString() ?? null })
}
</script>

<template>
    <div class="space-y-6 p-4 sm:p-6">
        <UTabs :items="tabs" :model-value="modelValue.mode" :content="false"
            class="mx-auto max-w-xs" :ui="{ list: 'rounded-full bg-elevation-2 dark:bg-elevation-4', trigger: 'rounded-full data-[state=active]:text-highlighted', indicator: 'rounded-full bg-elevation-1 shadow-sm dark:bg-elevation-6' }"
            @update:model-value="update({ mode: $event as RunSearchDates['mode'] })" />

        <template v-if="modelValue.mode === 'dates'">
            <UCalendar range :model-value="range" :min-value="currentDate" :number-of-months="twoMonths ? 2 : 1"
                :year-controls="false" :week-starts-on="1" color="primary" :size="twoMonths ? 'lg' : 'md'"
                class="w-full" :ui="{ body: 'flex flex-col gap-8 md:flex-row', grid: 'w-full', cellTrigger: 'rounded-full', headCell: 'text-muted', header: 'mb-4' }"
                @update:model-value="updateRange" />
            <div class="flex flex-wrap gap-2" :aria-label="t('runs.search.date_tolerance')">
                <UButton v-for="days in [0, 1, 2, 3, 7, 14]" :key="days" type="button" color="neutral"
                    :variant="modelValue.tolerance === days ? 'soft' : 'outline'" :aria-pressed="modelValue.tolerance === days"
                    class="rounded-full" :class="modelValue.tolerance === days ? 'ring-2 ring-brand-600 dark:ring-brand-400' : ''"
                    :label="days ? t('runs.search.tolerance_days', { count: days }) : t('runs.search.exact_dates')"
                    @click="update({ tolerance: days })" />
            </div>
        </template>
        <fieldset v-else class="space-y-4 text-center">
            <legend class="w-full text-base font-semibold text-highlighted">{{ t('runs.search.which_days') }}</legend>
            <div class="flex flex-wrap justify-center gap-3">
                <UButton v-for="days in (['any', 'weekend', 'weekday'] as const)" :key="days" type="button" color="neutral"
                    :variant="modelValue.days === days ? 'soft' : 'outline'" :aria-pressed="modelValue.days === days"
                    class="rounded-full px-5 py-3" :class="modelValue.days === days ? 'ring-2 ring-brand-600 dark:ring-brand-400' : ''"
                    :label="t(`runs.search.day_options.${days}`)" @click="update({ days })" />
            </div>
        </fieldset>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-default pt-4">
            <span class="flex items-center gap-2 text-xs text-muted"><UIcon name="i-lucide-clock" />{{ displayTimeZoneLabel }}</span>
            <div class="flex gap-2">
                <UButton color="neutral" variant="ghost" class="rounded-full" :label="t('runs.search.clear_dates')"
                    @click="update({ from: null, to: null, tolerance: 0, days: 'any' })" />
                <UButton v-if="showDone" color="primary" class="rounded-full" :label="t('runs.search.done')" @click="emit('done')" />
            </div>
        </div>
    </div>
</template>
