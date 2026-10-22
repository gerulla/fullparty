<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import GroupCreateChoiceField from './GroupCreateChoiceField.vue'
import GroupCreateTimeZonePicker from './GroupCreateTimeZonePicker.vue'
import GroupActivityTimeRangeSlider from './GroupActivityTimeRangeSlider.vue'
import { groupActivityTimeRanges, groupWeekdays, timeOfDayMinutes } from '@/utils/groupActivityWindow'
import { groupActivityTimeZoneOptions } from '@/utils/groupActivityTimeZones'
import type { GroupActiveDaysPreset, GroupActiveTimePreset, GroupActivityTimeRange, GroupCreateActivityWindow } from '@/Types/Groups'

const props = defineProps<{ modelValue: GroupCreateActivityWindow }>()
const emit = defineEmits<{ 'update:modelValue': [value: GroupCreateActivityWindow]; continue: [] }>()
const { t } = useI18n()
const timeZoneOptions = groupActivityTimeZoneOptions(new Date(), Intl.DateTimeFormat().resolvedOptions().timeZone)
const daysPresets = computed(() => ['every_day', 'weekends', 'custom'].map(value => ({
    value, label: t(`groups.index.create_flow.activity.day_presets.${value}`),
})))
const weekdays = computed(() => groupWeekdays.map(value => ({ value, label: t(`groups.common.active_days.${value}`) })))
const timeOptions = computed(() => [...Object.entries(groupActivityTimeRanges).map(([value, range]) => ({
    value,
    label: t(`groups.index.create_flow.activity.time_presets.${value}`),
    description: `${range.start}–${range.end}${range.end < range.start ? ` ${t('groups.index.create_flow.activity.next_day')}` : ''}`,
})), { value: 'custom', label: t('groups.index.create_flow.activity.time_presets.custom'), description: '' }])
const canContinue = computed(() => timeZoneOptions.some(option => option.value === props.modelValue.active_timezone)
    && props.modelValue.active_days.length > 0 && props.modelValue.active_days.every(day => groupWeekdays.some(value => value === day))
    && Number.isFinite(timeOfDayMinutes(props.modelValue.active_start_time))
    && Number.isFinite(timeOfDayMinutes(props.modelValue.active_end_time))
    && props.modelValue.active_start_time !== props.modelValue.active_end_time)
const overnight = computed(() => props.modelValue.active_start_time > props.modelValue.active_end_time)

function update(values: Partial<GroupCreateActivityWindow>) {
    emit('update:modelValue', { ...props.modelValue, ...values })
}

function selectDaysPreset(value: GroupActiveDaysPreset) {
    update({ daysPreset: value, active_days: value === 'every_day' ? [...groupWeekdays]
        : value === 'weekends' ? ['sat', 'sun'] : [...props.modelValue.customDays] })
}

function selectCustomDays(values: string[]) {
    const days = groupWeekdays.filter(day => values.includes(day))
    update({ customDays: days, active_days: days })
}

function selectTime(value: GroupActiveTimePreset) {
    const range = value === 'custom' ? props.modelValue.customTime : groupActivityTimeRanges[value]
    if (range) update({ timePreset: value, active_start_time: range.start, active_end_time: range.end })
}

function selectCustomTime(range: GroupActivityTimeRange) {
    update({ customTime: range, active_start_time: range.start, active_end_time: range.end })
}
</script>

<template>
    <div class="space-y-9 pt-4 sm:pt-6">
        <GroupCreateTimeZonePicker :model-value="modelValue.active_timezone" :options="timeZoneOptions"
            @update:model-value="update({ active_timezone: $event })" />
        <div class="space-y-5">
            <GroupCreateChoiceField :model-value="[modelValue.daysPreset]" :options="daysPresets" :multiple="false"
                :label="t('groups.index.create_modal.fields.active_days.label')"
                @update:model-value="selectDaysPreset($event[0] as GroupActiveDaysPreset)" />
            <GroupCreateChoiceField v-if="modelValue.daysPreset === 'custom'" :model-value="modelValue.active_days" :options="weekdays"
                :label="t('groups.index.create_flow.activity.custom_days')" @update:model-value="selectCustomDays" />
        </div>
        <GroupCreateChoiceField :model-value="[modelValue.timePreset]" :options="timeOptions" :multiple="false"
            :label="t('groups.index.create_flow.activity.active_time')" :help="t('groups.index.create_flow.activity.time_help')"
            @update:model-value="selectTime($event[0] as GroupActiveTimePreset)" />
        <GroupActivityTimeRangeSlider v-if="modelValue.timePreset === 'custom'" :model-value="modelValue.customTime"
            @update:model-value="selectCustomTime" />
        <p v-if="overnight" class="text-sm text-muted">{{ t('groups.index.create_flow.activity.overnight_help', { end: modelValue.active_end_time }) }}</p>
        <div class="flex justify-center">
            <UButton color="brand" size="lg" trailing-icon="i-lucide-arrow-right" :disabled="!canContinue"
                class="w-full justify-center rounded-lg sm:w-auto sm:min-w-40" @click="canContinue && emit('continue')">
                {{ t('general.continue') }}
            </UButton>
        </div>
    </div>
</template>
