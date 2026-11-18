<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRunSearchFilterCount } from '@/composables/useRunSearchFilterCount'
import type { RunDiscoveryLookups, RunSearchFilters, RunSearchState } from '@/Types/RunDiscovery'
import { activeRunFilterCount } from '@/utils/runBrowseSearch'

const RunSearchFiltersModal = defineAsyncComponent(() => import('./RunSearchFiltersModal.vue'))
const props = defineProps<{ search: RunSearchState; lookups: RunDiscoveryLookups; timezone: string }>()
const emit = defineEmits<{ apply: [filters: RunSearchFilters] }>()
const { t, n } = useI18n()
const trigger = ref<HTMLButtonElement | null>(null)
const opened = ref(false)
const open = ref(false)
const previewFilters = ref<RunSearchFilters | null>(null)
const count = computed(() => activeRunFilterCount(props.search.filters))
const { previewTotal, previewLoading } = useRunSearchFilterCount(
    () => open.value, () => props.search, () => previewFilters.value, () => props.timezone,
)

function showFilters() {
    previewFilters.value = null
    opened.value = true
    open.value = true
}

watch(() => props.search, () => { open.value = false })
</script>

<template>
    <Teleport defer to="#run-search-filters-trigger">
        <button ref="trigger" type="button" :aria-label="count ? `${t('runs.search.filters')} (${n(count)})` : t('runs.search.filters')"
            :aria-expanded="open" aria-haspopup="dialog"
            class="relative inline-flex h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-full text-sm font-semibold text-highlighted transition-colors hover:bg-elevation-2 focus-visible:outline-2 focus-visible:outline-brand-600 md:bg-elevation-1 md:shadow-md lg:px-4 dark:hover:bg-elevation-5 dark:focus-visible:outline-brand-400 dark:md:bg-elevation-4 dark:md:shadow-black/45"
            @click="showFilters">
            <UIcon name="i-lucide-sliders-horizontal" class="size-4.5 shrink-0" />
            <span class="hidden lg:inline">{{ t('runs.search.filters') }}</span>
            <span v-if="count" class="absolute end-0 top-0 inline-flex min-w-4 items-center justify-center rounded-full bg-brand-700 px-1 text-[10px] leading-4 text-white lg:static dark:bg-brand-400 dark:text-neutral-950">{{ n(count) }}</span>
        </button>
    </Teleport>
    <RunSearchFiltersModal v-if="opened" v-model:open="open" :filters="search.filters" :lookups="lookups" :timezone="timezone"
        :preview-total="previewTotal" :preview-loading="previewLoading" @preview="previewFilters = $event"
        @apply="emit('apply', $event)" @after:leave="trigger?.focus({ preventScroll: true })" />
</template>
