import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { route } from 'ziggy-js'
import { runSearchParams } from '@/utils/runBrowseSearch'
import type { RunSearchFilters, RunSearchState } from '@/Types/RunDiscovery'

export function useRunSearchFilterCount(
    isOpen: () => boolean,
    appliedSearch: () => RunSearchState | null,
    draftFilters: () => RunSearchFilters | null | undefined,
    searchTimezone: () => string,
) {
    const previewTotal = ref<number | null>(null)
    const previewLoading = ref(false)
    const query = computed(() => {
        const search = isOpen() && appliedSearch()
        if (!search) return null
        return runSearchParams({ ...search, filters: draftFilters() ?? search.filters }, searchTimezone()).toString()
    })

    watch(query, (queryString, previous, onCleanup) => {
        previewTotal.value = null
        previewLoading.value = queryString !== null
        if (queryString === null) return

        const request = new AbortController()
        const timer = setTimeout(async () => {
            try {
                const response = await axios.get<{ total: number }>(route('dashboard.runs.discover-count'), {
                    params: new URLSearchParams(queryString),
                    signal: request.signal,
                })
                if (!request.signal.aborted) previewTotal.value = response.data.total
            } catch {
                // Applying filters remains available when the count cannot be fetched.
            } finally {
                if (!request.signal.aborted) previewLoading.value = false
            }
        }, previous ? 250 : 0)

        onCleanup(() => {
            clearTimeout(timer)
            request.abort()
        })
    }, { immediate: true })

    return { previewTotal, previewLoading }
}
