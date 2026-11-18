<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import RunSearchDesktop from './RunSearchDesktop.vue'
import RunSearchMobile from './RunSearchMobile.vue'
import RunSearchPickerSkeleton from './RunSearchPickerSkeleton.vue'
import type { RunSearchField, RunSearchSegment } from './runSearchFields'
import type { RunSearchDates, RunSearchLookups, RunSearchState } from '@/Types/RunDiscovery'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'
import { emptyRunSearch } from '@/utils/runBrowseSearch'

const RunSearchDatePicker = defineAsyncComponent({
    loader: () => import('./RunSearchDatePicker.vue'),
    loadingComponent: RunSearchPickerSkeleton,
    delay: 0,
})
const RunSearchOptionPicker = defineAsyncComponent({
    loader: () => import('./RunSearchOptionPicker.vue'),
    loadingComponent: RunSearchPickerSkeleton,
    delay: 0,
})

const props = defineProps<{
    lookups: RunSearchLookups
    appliedSearch: RunSearchState | null
    optionsLoading?: boolean
    optionsFailed?: boolean
    loading?: boolean
    pinned?: boolean
}>()
const model = defineModel<RunSearchState>({ required: true })
const emit = defineEmits<{
    search: []
    open: []
    'retry-options': []
    'update:expanded': [expanded: boolean]
}>()
const { t } = useI18n()
const { formatDateOnly } = useDateTimeFormat()
const mobile = useBreakpoints(breakpointsTailwind).smaller('md')
const active = ref<RunSearchField | null>(null)
const activityOptions = computed(() => props.lookups.activity_types.map(item => ({
    value: item.value,
    label: item.label,
    image: item.small_image_url,
})))
function dateSummary(dates: RunSearchDates) {
    if (dates.mode === 'flexible') return t(`runs.search.day_options.${dates.days}`)
    if (!dates.from) return t('runs.search.anytime')
    const showYear = dates.to && dates.to.slice(0, 4) !== dates.from.slice(0, 4)
    const range = formatDateOnly(dates.from, { day: 'numeric', month: 'short', year: showYear ? 'numeric' : undefined }, dates.to ?? undefined)
    return dates.tolerance ? `${range} (${t('runs.search.tolerance_days', { count: dates.tolerance })})` : range
}

function describeSearch(state: RunSearchState, submitted = false): RunSearchSegment[] {
    const activity = props.lookups.activity_types.find(item => item.value === state.activity)
    const group = props.lookups.groups.find(item => item.value === state.group)
    return [
        { field: 'activity', label: t('runs.search.activity'), summary: activity?.label ?? (submitted ? t('runs.search.all_runs') : t('runs.search.any_activity')), image: activity?.small_image_url },
        { field: 'when', label: t('runs.search.when'), summary: dateSummary(state.dates) },
        { field: 'group', label: t('runs.search.group'), summary: group?.label ?? t('runs.search.any_group') },
    ]
}
const segments = computed(() => describeSearch(model.value))
const compactSegments = computed(() => describeSearch(props.appliedSearch ?? emptyRunSearch(), Boolean(props.appliedSearch)))

function open(field: RunSearchField) {
    if (!active.value) emit('open')
    active.value = field
}
function selectOption(field: 'activity' | 'group', value: string) {
    model.value = { ...model.value, [field]: value }
    if (field === 'activity') open('when')
}
function close() { active.value = null }
function submit() {
    close()
    emit('search')
}

watch(mobile, close)
watch(() => !!active.value && !mobile.value, value => emit('update:expanded', value), { flush: 'sync' })
defineExpose({ close })
</script>

<template>
    <div>
        <component :is="mobile ? RunSearchMobile : RunSearchDesktop"
            :pinned="pinned" :segments="segments" :compact-segments="compactSegments" :submitted="Boolean(appliedSearch)" :active="active" :loading="loading"
            @open="open" @close="close" @search="submit" @clear="model = emptyRunSearch()">
            <template #default="{ field }">
                <RunSearchDatePicker v-if="field === 'when'" :model-value="model.dates" :show-done="!mobile"
                    @update:model-value="model = { ...model, dates: $event }" @done="open('group')" />
                <RunSearchPickerSkeleton v-else-if="optionsLoading" />
                <div v-else-if="optionsFailed" class="space-y-2 p-5 text-sm">
                    <p role="status">{{ t('runs.search.options_failed') }}</p>
                    <UButton color="neutral" variant="outline" size="sm" class="rounded-full"
                        :label="t('runs.search.retry')" @click="emit('retry-options')" />
                </div>
                <RunSearchOptionPicker v-else-if="field === 'activity'" key="activity"
                    :options="activityOptions" :selected="model.activity"
                    :placeholder="t('runs.search.search_activities')" :any-label="t('runs.search.any_activity')"
                    @select="selectOption('activity', $event)" />
                <RunSearchOptionPicker v-else key="group" :options="lookups.groups" :selected="model.group"
                    :placeholder="t('runs.search.search_groups')" :any-label="t('runs.search.any_group')"
                    @select="selectOption('group', $event)" />
            </template>
        </component>
    </div>
</template>
