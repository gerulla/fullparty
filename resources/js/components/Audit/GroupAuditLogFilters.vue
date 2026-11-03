<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTimeDisplayMode } from '@/composables/useTimeDisplayMode'
import type { AuditLogFilters, AuditLogFilterOptions } from '@/Types/Audit'

const props = defineProps<{ modelValue: AuditLogFilters; options: AuditLogFilterOptions }>()
const emit = defineEmits<{ 'update:modelValue': [value: AuditLogFilters] }>()
const { t, te, locale } = useI18n()
const { displayTimeZoneLabel, withDisplayTimeZone } = useTimeDisplayMode()
const controlUi = { base: 'rounded-lg', content: 'max-w-[calc(100vw-2rem)]', itemLabel: 'whitespace-normal break-words' }
const categories = ['__all__', 'runs', 'members', 'resources', 'settings'] as const
const categoryLabel = (value: string) => t(`audit_log.categories.${value === '__all__' ? 'all' : value}`)
const translated = (key: string) => te(key) ? t(key) : key
const actionOptions = computed(() => [
    { value: '__all__', label: t('audit_log.filters.any_action') },
    ...props.options.actions.map(option => ({ ...option, label: translated(option.label) })),
])
const severityOptions = computed(() => [
    { value: '__all__', label: t('audit_log.filters.any_severity') },
    ...props.options.severities.map(option => ({ ...option, label: translated(option.label) })),
])
const userOptions = computed(() => [{ value: '__all__', label: t('audit_log.filters.any_user') }, ...props.options.users])
const runOptions = computed(() => {
    const formatter = new Intl.DateTimeFormat(locale.value, withDisplayTimeZone({ day: 'numeric', month: 'short', year: 'numeric' }))
    return [
        { value: '__all__', label: t('audit_log.filters.all_runs') },
        ...(props.options.activities ?? []).map(activity => ({
            value: activity.value,
            label: [activity.title || t('audit_log.filters.unnamed_activity', { id: activity.value }), activity.starts_at ? formatter.format(new Date(activity.starts_at)) : ''].filter(Boolean).join(' · '),
        })),
    ]
})
const dateOptions = computed(() => ['all_time', 'today', 'last_7_days', 'last_30_days', 'custom_dates'].map(value => ({
    value, label: t(`audit_log.filters.${value}`),
})))

function dateOffset(days: number) {
    const parts = new Intl.DateTimeFormat('en-CA', withDisplayTimeZone({ year: 'numeric', month: '2-digit', day: '2-digit' })).formatToParts(new Date())
    const part = (type: string) => Number(parts.find(part => part.type === type)?.value)
    return new Date(Date.UTC(part('year'), part('month') - 1, part('day') + days)).toISOString().slice(0, 10)
}
const customRangeSelected = ref(false)
const dateRange = computed(() => {
    if (customRangeSelected.value) return 'custom_dates'
    const { afterDate, beforeDate } = props.modelValue
    if (!afterDate && !beforeDate) return 'all_time'
    if (beforeDate === dateOffset(0)) {
        if (afterDate === dateOffset(0)) return 'today'
        if (afterDate === dateOffset(-6)) return 'last_7_days'
        if (afterDate === dateOffset(-29)) return 'last_30_days'
    }
    return 'custom_dates'
})
const customDates = computed(() => dateRange.value === 'custom_dates')

function update(key: keyof AuditLogFilters, value: string) {
    emit('update:modelValue', { ...props.modelValue, [key]: value })
}
function selectDateRange(value: string) {
    if (value === 'custom_dates') {
        customRangeSelected.value = true
        return
    }
    customRangeSelected.value = false
    const days = value === 'today' ? 0 : value === 'last_7_days' ? -6 : -29
    emit('update:modelValue', {
        ...props.modelValue,
        afterDate: value === 'all_time' ? '' : dateOffset(days),
        beforeDate: value === 'all_time' ? '' : dateOffset(0),
    })
}
function clear() {
    customRangeSelected.value = false
    emit('update:modelValue', { ...props.modelValue, search: '', category: '__all__', activity: '__all__', user: '__all__', severity: '__all__', action: '__all__', afterDate: '', beforeDate: '' })
}
function selectedLabel(options: Array<{ value: string; label: string }>, value: string) {
    return options.find(option => option.value === value)?.label ?? value
}
const chips = computed(() => {
    const value = props.modelValue
    const items: Array<{ key: keyof AuditLogFilters | 'dates'; label: string; reset: string }> = []
    if (value.search.trim()) items.push({ key: 'search', label: t('audit_log.filters.search_chip', { query: value.search }), reset: '' })
    if (value.activity !== '__all__') items.push({ key: 'activity', label: t('audit_log.filters.run_chip', { name: selectedLabel(runOptions.value, value.activity) }), reset: '__all__' })
    if (value.user !== '__all__') items.push({ key: 'user', label: t('audit_log.filters.user_chip', { name: selectedLabel(userOptions.value, value.user) }), reset: '__all__' })
    if (value.action !== '__all__') items.push({ key: 'action', label: t('audit_log.filters.action_chip', { name: selectedLabel(actionOptions.value, value.action) }), reset: '__all__' })
    if (value.severity !== '__all__') items.push({ key: 'severity', label: t('audit_log.filters.severity_chip', { name: selectedLabel(severityOptions.value, value.severity) }), reset: '__all__' })
    if (value.category && value.category !== '__all__') items.push({ key: 'category', label: categoryLabel(value.category), reset: '__all__' })
    if (dateRange.value !== 'all_time' && !customDates.value) items.push({ key: 'dates', label: t(`audit_log.filters.${dateRange.value}`), reset: '' })
    else {
        if (value.afterDate) items.push({ key: 'afterDate', label: t('audit_log.filters.from_chip', { date: value.afterDate }), reset: '' })
        if (value.beforeDate) items.push({ key: 'beforeDate', label: t('audit_log.filters.until_chip', { date: value.beforeDate }), reset: '' })
    }
    return items
})
const extraFilterCount = computed(() => [props.modelValue.action, props.modelValue.severity, props.modelValue.user].filter(value => value !== '__all__').length)
function removeChip(chip: typeof chips.value[number]) {
    if (chip.key === 'dates') selectDateRange('all_time')
    else update(chip.key, chip.reset)
}
</script>

<template>
    <div class="@container space-y-4">
        <div class="grid min-w-0 gap-3 @xl:grid-cols-2 @4xl:grid-cols-[1.4fr_1fr_1fr]">
            <UInput :model-value="modelValue.search" icon="i-lucide-search" class="min-w-0 w-full @xl:col-span-2 @4xl:col-span-1" size="lg" :maxlength="200"
                :placeholder="t('audit_log.filters.search_logs')" :aria-label="t('audit_log.filters.search_logs')" :ui="controlUi" @update:model-value="update('search', String($event))" />
            <USelectMenu :model-value="modelValue.activity" :items="runOptions" value-key="value" class="min-w-0 w-full" size="lg"
                :aria-label="t('audit_log.filters.activity_label')" :search-input="{ placeholder: t('audit_log.filters.search_activity') }" :ui="controlUi" @update:model-value="update('activity', $event)" />
            <USelect :model-value="dateRange" :items="dateOptions" value-key="value" class="min-w-0 w-full" size="lg" icon="i-lucide-calendar-days"
                :aria-label="t('audit_log.filters.date_range')" :ui="controlUi" @update:model-value="selectDateRange($event)" />
        </div>
        <div v-if="customDates" class="grid gap-3 @sm:grid-cols-2">
            <UFormField :label="t('audit_log.filters.after_date.label')" class="min-w-0">
                <UInput :model-value="modelValue.afterDate" type="date" class="w-full" :ui="controlUi" @update:model-value="update('afterDate', String($event))" />
            </UFormField>
            <UFormField :label="t('audit_log.filters.before_date.label')" class="min-w-0">
                <UInput :model-value="modelValue.beforeDate" type="date" class="w-full" :ui="controlUi" @update:model-value="update('beforeDate', String($event))" />
            </UFormField>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-default pb-3">
            <div class="flex min-w-0 flex-wrap gap-1" role="group" :aria-label="t('audit_log.filters.title')">
                <UButton v-for="category in categories" :key="category" type="button" :label="categoryLabel(category)"
                    :color="(modelValue.category || '__all__') === category ? 'brand' : 'neutral'"
                    :variant="(modelValue.category || '__all__') === category ? 'soft' : 'ghost'"
                    :aria-pressed="(modelValue.category || '__all__') === category" class="rounded-lg" @click="update('category', category)" />
            </div>
            <UPopover :content="{ align: 'end' }" :ui="{ content: 'max-w-[calc(100vw-2rem)] rounded-lg' }">
                <UButton type="button" icon="i-lucide-list-filter" trailing-icon="i-lucide-chevron-down" color="neutral" variant="outline" class="rounded-lg" :label="t('audit_log.filters.more')">
                    <template #trailing>
                        <UBadge v-if="extraFilterCount" color="brand" size="sm" variant="soft" :label="String(extraFilterCount)" />
                        <UIcon name="i-lucide-chevron-down" class="size-4" />
                    </template>
                </UButton>
                <template #content>
                    <div class="w-80 max-w-[calc(100vw-2rem)] space-y-4 p-4">
                        <UFormField :label="t('audit_log.filters.user.label')">
                            <USelectMenu :model-value="modelValue.user" :items="userOptions" value-key="value" class="w-full" :aria-label="t('audit_log.filters.user.label')" :ui="controlUi" @update:model-value="update('user', $event)" />
                        </UFormField>
                        <UFormField :label="t('audit_log.filters.severity.label')">
                            <USelect :model-value="modelValue.severity" :items="severityOptions" value-key="value" class="w-full" :ui="controlUi" @update:model-value="update('severity', $event)" />
                        </UFormField>
                        <UFormField :label="t('audit_log.filters.action.label')">
                            <USelectMenu :model-value="modelValue.action" :items="actionOptions" value-key="value" class="w-full" :aria-label="t('audit_log.filters.action.label')" :ui="controlUi" @update:model-value="update('action', $event)" />
                        </UFormField>
                    </div>
                </template>
            </UPopover>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <UButton v-for="chip in chips" :key="chip.key" type="button" color="neutral" variant="soft" size="xs" trailing-icon="i-lucide-x"
                    class="max-w-full rounded-full" :ui="{ label: 'truncate' }" :label="chip.label"
                    :aria-label="t('audit_log.filters.remove', { filter: chip.label })" @click="removeChip(chip)" />
                <UButton v-if="chips.length" type="button" color="brand" variant="link" size="sm" :label="t('audit_log.filters.clear')" @click="clear" />
            </div>
            <p class="text-xs text-muted">{{ t('audit_log.list.timezone', { zone: displayTimeZoneLabel }) }}</p>
        </div>
    </div>
</template>
