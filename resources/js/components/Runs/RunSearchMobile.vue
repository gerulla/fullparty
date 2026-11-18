<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import FinderNavigation from '@/components/Navigation/FinderNavigation.vue'
import TimeDisplayModeToggle from '@/components/Navigation/TimeDisplayModeToggle.vue'
import RunSearchPickerSkeleton from './RunSearchPickerSkeleton.vue'
import type { RunSearchField, RunSearchSegment } from './runSearchFields'

const props = defineProps<{
    segments: RunSearchSegment[]
    compactSegments: RunSearchSegment[]
    active: RunSearchField | null
    submitted: boolean
    pinned?: boolean
    loading?: boolean
}>()
const emit = defineEmits<{ open: [field: RunSearchField]; close: []; search: []; clear: [] }>()
defineSlots<{ default(props: { field: RunSearchField }): unknown }>()
const { t } = useI18n()
const id = useId()
const triggerTitle = computed(() => props.submitted ? props.compactSegments[0].summary : t('navigation.sidebar.find_runs'))
const triggerSummary = computed(() => props.compactSegments.slice(props.submitted ? 1 : 0).map(segment => segment.summary).join(' · '))
const fade = 'motion-safe:data-[state=open]:animate-[fade-in_200ms_ease-out] motion-safe:data-[state=closed]:animate-[fade-out_160ms_ease-in]'
const entered = ref(false)
const displayedField = ref<RunSearchField>('activity')
const mountedFields = ref<RunSearchField[]>([])
const fieldTrigger = ref<HTMLButtonElement | null>(null)

function openField(field: RunSearchField, event: MouseEvent) {
    fieldTrigger.value = event.currentTarget as HTMLButtonElement
    emit('open', field)
}

function restoreFieldFocus(event: Event) {
    if (!fieldTrigger.value?.isConnected) return
    event.preventDefault()
    fieldTrigger.value.focus({ preventScroll: true })
    fieldTrigger.value = null
}

watch([() => props.active, entered], ([field, ready]) => {
    // Keep the last section on screen while the dialog fades out.
    if (!field) return
    displayedField.value = field
    if (ready && !mountedFields.value.includes(field)) mountedFields.value.push(field)
}, { immediate: true })

function afterLeave() {
    if (props.active) return
    entered.value = false
    mountedFields.value = []
}

function next() {
    if (props.active === 'group') emit('search')
    else emit('open', props.active === 'activity' ? 'when' : 'group')
}
</script>

<template>
    <div v-if="pinned" role="search" :aria-label="t('navigation.sidebar.find_runs')"
        class="grid min-h-14 w-full min-w-0 grid-cols-[minmax(0,1.2fr)_repeat(2,minmax(0,1fr))_auto] items-center rounded-full bg-elevation-1 p-1 shadow-md dark:bg-elevation-4 dark:shadow-black/45">
        <button v-for="segment in segments" :key="segment.field" type="button" aria-haspopup="dialog"
            :aria-expanded="active === segment.field" :aria-label="segment.label + ', ' + segment.summary"
            class="flex min-h-11 min-w-0 flex-col justify-center rounded-full px-2 text-start focus-visible:outline-2 focus-visible:outline-brand-600 first:ps-3 dark:focus-visible:outline-brand-400"
            @click="openField(segment.field, $event)">
            <span class="w-full truncate text-xs font-semibold text-highlighted">{{ segment.label }}</span>
            <span class="w-full truncate text-xs text-muted">{{ segment.summary }}</span>
        </button>
        <UButton type="button" color="primary" icon="i-lucide-search" :aria-label="t('runs.search.submit')" :loading="loading"
            class="size-11 justify-center rounded-full bg-brand-700 text-white hover:bg-brand-600 dark:bg-brand-400 dark:text-neutral-950 dark:hover:bg-brand-300"
            @click="emit('search')" />
    </div>
    <UModal fullscreen :overlay="!pinned" :transition="false" :open="Boolean(active)" :title="t('navigation.sidebar.find_runs')"
        :content="pinned ? { onCloseAutoFocus: restoreFieldFocus } : undefined"
        :ui="{ overlay: fade, content: `${fade} flex h-dvh flex-col divide-y-0 overflow-hidden bg-elevation-2 dark:bg-elevation-1 [--ui-primary:var(--color-brand-700)] dark:[--ui-primary:var(--color-brand-400)]` }"
        @update:open="$event ? emit('open', 'activity') : emit('close')"
        @after:enter="entered = Boolean(active)" @after:leave="afterLeave">
        <template v-if="!pinned" #default>
            <button type="button" class="flex min-h-12 w-full min-w-0 items-center gap-3 rounded-full bg-elevation-1 px-4 py-2 shadow-md focus-visible:outline-2 focus-visible:outline-brand-600 dark:bg-elevation-4 dark:shadow-black/45 dark:focus-visible:outline-brand-400"
                :class="submitted ? 'text-center' : 'text-start'">
                <UIcon v-if="!submitted" name="i-lucide-search" class="size-5 shrink-0 text-brand-700 dark:text-brand-400" />
                <span class="min-w-0 flex-1">
                    <strong class="block truncate text-sm font-semibold text-highlighted">{{ triggerTitle }}</strong>
                    <span class="block truncate text-xs text-muted">{{ triggerSummary }}</span>
                </span>
            </button>
        </template>
        <template #content>
            <header class="grid shrink-0 grid-cols-[1fr_auto_1fr] items-center gap-2 px-4 pb-3 pt-[max(1rem,env(safe-area-inset-top))]">
                <UButton color="neutral" variant="ghost" icon="i-lucide-x" class="size-11 justify-center justify-self-start rounded-full"
                    :aria-label="t('general.close')" @click="emit('close')" />
                <h2 class="text-base font-semibold text-highlighted">{{ t('navigation.sidebar.find_runs') }}</h2>
                <div class="justify-self-end"><TimeDisplayModeToggle /></div>
            </header>
            <FinderNavigation class="mx-2 shrink-0" @navigate="emit('close')" />
            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain px-3 pt-1 pb-4">
                <section v-for="segment in segments" :key="segment.field" class="overflow-hidden bg-elevation-1"
                    :class="displayedField === segment.field ? 'rounded-3xl shadow-lg dark:bg-elevation-5 dark:shadow-black/55' : 'rounded-2xl shadow-sm dark:bg-elevation-3 dark:shadow-black/35'">
                    <button :id="id + '-step-' + segment.field" type="button"
                        class="flex w-full items-center justify-between gap-4 px-4 py-4 text-start focus-visible:-outline-offset-4"
                        :aria-expanded="displayedField === segment.field" :aria-controls="id + '-panel-' + segment.field"
                        @click="emit('open', segment.field)">
                        <span class="font-semibold text-highlighted" :class="{ 'text-xl': displayedField === segment.field }">{{ segment.label }}</span>
                        <span v-if="displayedField !== segment.field" class="truncate text-xs text-muted">{{ segment.summary }}</span>
                    </button>
                    <div :id="id + '-panel-' + segment.field" role="region" :aria-labelledby="id + '-step-' + segment.field"
                        class="grid transition-[grid-template-rows] duration-300 ease-out motion-reduce:transition-none"
                        :class="displayedField === segment.field ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
                        :inert="displayedField !== segment.field">
                        <div class="min-h-0 overflow-hidden">
                            <slot v-if="mountedFields.includes(segment.field)" :field="segment.field" />
                            <RunSearchPickerSkeleton v-else-if="displayedField === segment.field" />
                        </div>
                    </div>
                </section>
            </div>
            <footer class="flex shrink-0 items-center justify-between gap-4 px-5 pt-3 pb-[max(.75rem,env(safe-area-inset-bottom))]">
                <UButton color="neutral" variant="link" class="underline" :label="t('runs.search.clear_all')" @click="emit('clear')" />
                <UButton color="primary" size="lg" class="min-h-11 justify-center rounded-full bg-brand-700 px-6 text-white hover:bg-brand-600 dark:bg-brand-400 dark:text-neutral-950 dark:hover:bg-brand-300"
                    :label="displayedField === 'group' ? t('runs.search.submit') : t('general.next')"
                    :trailing-icon="displayedField === 'group' ? 'i-lucide-search' : 'i-lucide-arrow-right'" :loading="loading"
                    @click="next" />
            </footer>
        </template>
    </UModal>
</template>
