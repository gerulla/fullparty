<script setup lang="ts">
import { computed } from 'vue'
import { SliderRoot, SliderThumb } from 'reka-ui'
import { useI18n } from 'vue-i18n'
import { activityWindowSegments, minutesToTimeOfDay, timeOfDayMinutes } from '@/utils/groupActivityWindow'
import type { GroupActivityTimeRange } from '@/Types/Groups'

const props = defineProps<{ modelValue: GroupActivityTimeRange }>()
const emit = defineEmits<{ 'update:modelValue': [value: GroupActivityTimeRange] }>()
const { t } = useI18n()
const start = computed(() => timeOfDayMinutes(props.modelValue.start))
const end = computed(() => timeOfDayMinutes(props.modelValue.end))
const segments = computed(() => activityWindowSegments(start.value, end.value))
const overnight = computed(() => start.value > end.value)
const ticks = [0, 360, 720, 1080, 1439]
const handles = ['start', 'end'] as const

function move(handle: keyof GroupActivityTimeRange, values: number[] | undefined) {
    if (!values?.length || !Number.isFinite(values[0])) return
    emit('update:modelValue', { ...props.modelValue, [handle]: minutesToTimeOfDay(values[0]) })
}
</script>

<template>
    <div class="space-y-4" role="group" :aria-label="t('groups.index.create_flow.activity.custom_time')">
        <p class="text-sm text-muted">{{ t('groups.index.create_flow.activity.slider_help') }}</p>
        <div class="flex justify-between gap-4">
            <div v-for="handle in handles" :key="handle" class="flex items-center gap-2 text-sm">
                <span class="h-5 w-2 rounded-full" aria-hidden="true"
                    :class="handle === 'start' ? 'bg-brand-700 dark:bg-brand-300' : 'bg-brand-400 dark:bg-brand-500'" />
                <span class="text-muted">{{ t(`groups.index.create_flow.activity.${handle}`) }}</span>
                <span class="font-semibold text-highlighted tabular-nums">{{ modelValue[handle] }}</span>
            </div>
        </div>
        <div class="px-6">
            <div class="relative h-24" dir="ltr">
                <div class="absolute inset-x-0 top-1/2 h-2 -translate-y-1/2 overflow-hidden rounded-full bg-elevation-4 dark:bg-elevation-5" aria-hidden="true">
                    <div v-for="(segment, index) in segments" :key="index"
                        class="absolute inset-y-0 bg-brand-600 dark:bg-brand-400"
                        :style="{ left: `${segment.left}%`, width: `${segment.width}%` }" />
                </div>
                <!-- Independent Reka thumbs keep their start/end identities when crossing; a sorted range slider cannot. -->
                <SliderRoot v-for="handle in handles" :key="handle" :model-value="[handle === 'start' ? start : end]"
                    :min="0" :max="1439" :step="15" thumb-alignment="overflow" dir="ltr"
                    class="pointer-events-none absolute inset-0 touch-none select-none"
                    @update:model-value="move(handle, $event)">
                    <SliderThumb :aria-label="t(`groups.index.create_flow.activity.${handle}`)" :aria-valuetext="modelValue[handle]"
                        class="pointer-events-auto absolute flex h-12 w-11 cursor-ew-resize justify-center rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 focus-visible:ring-offset-elevation-2"
                        :class="handle === 'start' ? 'top-0 items-end pb-1' : 'top-1/2 items-start pt-1'">
                        <span class="h-7 w-3 rounded-full shadow-sm ring-2 ring-elevation-2" aria-hidden="true"
                            :class="handle === 'start' ? 'bg-brand-700 dark:bg-brand-300' : 'bg-brand-400 dark:bg-brand-500'" />
                    </SliderThumb>
                </SliderRoot>
            </div>
            <div class="relative h-5 text-xs text-muted tabular-nums" aria-hidden="true">
                <span v-for="tick in ticks" :key="tick" class="absolute -translate-x-1/2" :style="{ left: `${tick / 1439 * 100}%` }">
                    {{ minutesToTimeOfDay(tick) }}
                </span>
            </div>
        </div>
        <p v-if="start === end" role="alert" class="text-sm text-error">{{ t('groups.index.create_flow.activity.same_time') }}</p>
        <p v-else class="text-center text-sm font-medium text-highlighted tabular-nums" aria-live="polite" aria-atomic="true">
            {{ modelValue.start }}–{{ modelValue.end }}
            <span v-if="overnight" class="ml-1 text-brand-700 dark:text-brand-300">{{ t('groups.index.create_flow.activity.next_day') }}</span>
        </p>
    </div>
</template>
