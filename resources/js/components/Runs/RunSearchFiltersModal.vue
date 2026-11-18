<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RunDiscoveryLookups, RunSearchFilters } from '@/Types/RunDiscovery'
import { activeRunFilterCount, emptyRunSearchFilters } from '@/utils/runBrowseSearch'

const open = defineModel<boolean>('open', { required: true })
const props = defineProps<{
    filters: RunSearchFilters
    lookups: RunDiscoveryLookups
    timezone: string
    previewTotal: number | null
    previewLoading: boolean
}>()
const emit = defineEmits<{
    preview: [filters: RunSearchFilters]
    apply: [filters: RunSearchFilters]
}>()
const { t, n } = useI18n()
const defaults = emptyRunSearchFilters()
const draft = ref<RunSearchFilters>({ ...defaults })
watch(open, visible => {
    if (visible) draft.value = { ...props.filters }
}, { immediate: true })

const quickFilters = [
    { key: 'beginner_friendly', label: 'runs.discovery.filters.labels.beginner_friendly', icon: 'i-lucide-sprout', color: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-300' },
    { key: 'saved_only', label: 'runs.discovery.filters.sections.saved_runs', icon: 'i-lucide-bookmark', color: 'bg-brand-100 text-brand-700 dark:bg-brand-400/15 dark:text-brand-300' },
] as const
const any = computed(() => ({ label: t('runs.discovery.filters.options.roles.any'), value: 'any' }))
const joinOptions = computed(() => [
    any.value,
    ...['applications_open', 'direct_join'].map(value => ({ value, label: t(`runs.discovery.filters.options.application_statuses.${value}`) })),
])
const roleOptions = computed(() => (['any', 'tank', 'healer', 'dps'] as const).map(value => ({
    value, label: t(`runs.discovery.filters.options.roles.${value}`),
})))
const preferenceFields = computed(() => [
    {
        key: 'time_of_day',
        label: t('runs.search.time_of_day'),
        hint: props.timezone,
        options: ['any', 'morning', 'afternoon', 'evening', 'night'].map(value => ({
            value, label: t(`runs.discovery.filters.options.time_of_day.${value}`),
        })),
    },
    { key: 'language', label: t('runs.discovery.filters.sections.language'), options: [any.value, ...props.lookups.languages] },
    {
        key: 'run_style', label: t('runs.discovery.filters.sections.run_style'),
        options: [any.value, ...props.lookups.run_styles.map(value => ({ value, label: t(`runs.discovery.filters.options.run_styles.${value}`) }))],
    },
    {
        key: 'intensity', label: t('runs.discovery.filters.sections.intensity'),
        options: [any.value, ...props.lookups.intensities.map(value => ({ value, label: t(`runs.discovery.filters.options.intensity.${value}`) }))],
    },
] as const)
const locationFields = computed(() => [
    { key: 'region', label: t('runs.search.region'), options: [any.value, ...props.lookups.regions] },
    { key: 'datacenter', label: t('general.datacenter'), options: [any.value, ...props.lookups.datacenters.filter(item => !draft.value.region || item.region === draft.value.region)] },
] as const)
const sections = computed(() => [
    { title: t('runs.search.run_preferences'), fields: preferenceFields.value },
    { title: t('runs.discovery.filters.sections.datacenter_world'), fields: locationFields.value },
])
const applyLabel = computed(() => props.previewTotal === null || props.previewLoading
    ? t('runs.search.show_runs')
    : t('runs.search.show_count', { count: n(props.previewTotal) }, props.previewTotal))

function update(filters: Partial<RunSearchFilters>) {
    const next = { ...draft.value, ...filters }
    if (next.region && next.datacenter && !props.lookups.datacenters.some(item => item.value === next.datacenter && item.region === next.region)) {
        next.datacenter = ''
    }
    draft.value = next
    emit('preview', { ...next })
}

function apply() {
    emit('apply', { ...draft.value })
    open.value = false
}

const radioUi = {
    fieldset: 'gap-1 rounded-2xl bg-elevation-2 p-1 dark:bg-elevation-4',
    item: 'min-h-11 min-w-0 flex-1 cursor-pointer items-center justify-center rounded-xl border border-transparent px-1.5 py-2 has-data-[state=checked]:border-primary has-data-[state=checked]:bg-elevation-1 has-data-[state=checked]:shadow-sm dark:has-data-[state=checked]:bg-elevation-5 has-focus-visible:outline-2 has-focus-visible:outline-offset-1 has-focus-visible:outline-primary',
    label: 'text-sm leading-tight',
}
const selectUi = {
    base: 'w-full min-h-11 rounded-xl bg-elevation-1 text-highlighted ring-default dark:bg-elevation-4',
    content: 'rounded-xl bg-elevation-1 shadow-lg dark:bg-elevation-4 [--ui-primary:var(--color-brand-700)] dark:[--ui-primary:var(--color-brand-400)]',
    item: 'rounded-lg',
}
const fade = 'motion-safe:data-[state=open]:animate-[fade-in_200ms_ease-out] motion-safe:data-[state=closed]:animate-[fade-out_160ms_ease-in]'
const modalUi = {
    overlay: fade,
    content: `${fade} bottom-0 top-auto h-[calc(100dvh-max(.75rem,env(safe-area-inset-top)))] max-h-none w-full max-w-none translate-y-0 divide-y-0 rounded-t-3xl rounded-b-none bg-elevation-1 text-default dark:bg-elevation-2 sm:max-h-none md:bottom-auto md:top-1/2 md:h-auto md:max-h-[min(46rem,calc(100dvh-4rem))] md:max-w-xl md:-translate-y-1/2 md:rounded-3xl [--ui-primary:var(--color-brand-700)] dark:[--ui-primary:var(--color-brand-400)]`,
    header: 'relative min-h-14 shrink-0 justify-center px-14 py-3 sm:px-14',
    title: 'text-lg font-semibold',
    close: 'top-1/2 end-3 size-11 -translate-y-1/2 justify-center rounded-full',
    body: 'min-h-0 overflow-y-auto overscroll-contain px-5 py-0 sm:px-7 sm:py-0',
    footer: 'shrink-0 justify-between gap-4 px-5 pt-3 pb-[max(.85rem,env(safe-area-inset-bottom))] sm:px-7',
}
</script>

<template>
    <UModal v-model:open="open" :title="t('runs.search.filters')" :transition="false" :ui="modalUi">
        <template #body>
            <div class="divide-y divide-default">
                <section class="space-y-3 pb-5 pt-3">
                    <h3 class="text-base font-semibold text-highlighted">{{ t('runs.search.quick_filters') }}</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <UButton v-for="filter in quickFilters" :key="filter.key" type="button" color="neutral" variant="outline"
                            class="relative min-h-24 flex-col items-start justify-between gap-3 whitespace-normal rounded-2xl p-3.5 text-start text-sm font-semibold text-highlighted"
                            :class="draft[filter.key] ? 'bg-brand-50 ring-2 ring-primary dark:bg-brand-400/10' : 'bg-elevation-1 ring-default hover:bg-elevation-2 dark:bg-elevation-3 dark:hover:bg-elevation-4'"
                            :aria-pressed="draft[filter.key]" @click="update({ [filter.key]: !draft[filter.key] })">
                            <span class="grid size-9 place-items-center rounded-xl" :class="filter.color">
                                <UIcon :name="filter.icon" class="size-5" />
                            </span>
                            <UIcon v-if="draft[filter.key]" name="i-lucide-circle-check" class="absolute end-3 top-3 size-4 text-primary" />
                            {{ t(filter.label) }}
                        </UButton>
                    </div>
                </section>
                <section class="space-y-3 py-5">
                    <h3 class="text-base font-semibold text-highlighted">{{ t('runs.search.how_to_join') }}</h3>
                    <URadioGroup :aria-label="t('runs.search.how_to_join')" :items="joinOptions" :model-value="draft.application_status ?? 'any'"
                        orientation="horizontal" variant="card" indicator="hidden" :ui="radioUi"
                        @update:model-value="update({ application_status: $event === 'any' ? null : $event })" />
                </section>
                <section class="space-y-3 py-5">
                    <h3 class="text-base font-semibold text-highlighted">{{ t('runs.discovery.filters.sections.role_needed') }}</h3>
                    <URadioGroup :aria-label="t('runs.discovery.filters.sections.role_needed')" :items="roleOptions" :model-value="draft.role_category ?? 'any'"
                        orientation="horizontal" variant="card" indicator="hidden" :ui="radioUi"
                        @update:model-value="update({ role_category: $event })" />
                </section>
                <section v-for="section in sections" :key="section.title" class="space-y-3 py-5">
                    <h3 class="text-base font-semibold text-highlighted">{{ section.title }}</h3>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <UFormField v-for="field in section.fields" :key="field.key" :label="field.label" :hint="'hint' in field ? field.hint : undefined">
                            <USelect :items="field.options" :model-value="draft[field.key] || 'any'" :ui="selectUi"
                                @update:model-value="update({ [field.key]: $event === 'any' ? defaults[field.key] : $event })" />
                        </UFormField>
                    </div>
                </section>
            </div>
        </template>
        <template #footer>
            <UButton type="button" color="neutral" variant="link" class="min-h-11 rounded-lg px-1 underline"
                :label="t('runs.search.clear_all')" :disabled="activeRunFilterCount(draft) === 0" @click="update(defaults)" />
            <UButton type="button" size="lg" class="min-h-11 justify-center rounded-xl bg-brand-700 px-5 font-semibold text-white hover:bg-brand-600 dark:bg-brand-400 dark:text-neutral-950 dark:hover:bg-brand-300"
                @click="apply">
                <UIcon v-if="previewLoading" name="i-lucide-loader-circle" class="size-4 motion-safe:animate-spin" />
                <span aria-live="polite" aria-atomic="true">{{ applyLabel }}</span>
            </UButton>
        </template>
    </UModal>
</template>
