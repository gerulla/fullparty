import { onBeforeUnmount, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'
import { copyRunSearch, emptyRunSearch, hasRunSearch, readRunSearch, readRunSearchTimezone, runSearchParams } from '@/utils/runBrowseSearch'
import type { RunDiscoveryDiscoverResponse, RunDiscoveryLookups, RunSearchFilters, RunSearchResultSort, RunSearchState } from '@/Types/RunDiscovery'

export function useRunBrowseSearch(lookups: () => RunDiscoveryLookups, perPage: () => number) {
    const page = usePage()
    const { displayTimeZone } = useDateTimeFormat()
    const currentDisplayTimezone = () => displayTimeZone.value ?? Intl.DateTimeFormat().resolvedOptions().timeZone
    const searchTimezone = ref('UTC')
    const applied = ref<RunSearchState>(emptyRunSearch())
    const active = ref(false)
    const loading = ref(false)
    const failed = ref(false)
    const result = ref<RunDiscoveryDiscoverResponse | null>(null)
    const currentPage = ref(1)
    let request: AbortController | null = null

    async function fetchResults(keepCurrentResults = false) {
        request?.abort()
        const pending = new AbortController()
        request = pending
        loading.value = !keepCurrentResults
        failed.value = false
        if (!keepCurrentResults) result.value = null
        try {
            const params = runSearchParams(applied.value, searchTimezone.value, currentPage.value)
            params.set('per_page', String(perPage()))
            const response = await axios.get<RunDiscoveryDiscoverResponse>(route('dashboard.runs.discover'), {
                params, signal: pending.signal,
            })
            if (request === pending) result.value = response.data
        } catch {
            if (request === pending && !pending.signal.aborted) failed.value = true
        } finally {
            if (request === pending) loading.value = false
        }
    }

    function readLocation() {
        const params = new URL(page.url, window.location.origin).searchParams
        // Date and time-of-day filters keep the timezone in which the search was submitted.
        searchTimezone.value = readRunSearchTimezone(params, currentDisplayTimezone())
        applied.value = readRunSearch(params, lookups())
        active.value = hasRunSearch(params, applied.value)
        currentPage.value = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1)
        if (active.value) void fetchResults()
        else {
            request?.abort()
            request = null
            loading.value = false
            failed.value = false
            result.value = null
        }
    }

    function visit(state: RunSearchState, number = 1, timezone = searchTimezone.value) {
        const url = `${route('dashboard.runs.index', {}, false)}?${runSearchParams(state, timezone, number)}`
        if (url === page.url) {
            applied.value = copyRunSearch(state)
            currentPage.value = number
            void fetchResults()
        } else {
            router.push({ url, preserveScroll: true, preserveState: true })
        }
    }

    const selectActivity = (activity: string) => visit({ ...emptyRunSearch(), activity }, 1, currentDisplayTimezone())
    const applyFilters = (filters: RunSearchFilters) => visit({ ...applied.value, filters })
    function setSort(sort: RunSearchResultSort) {
        if (sort !== applied.value.sort) visit({ ...applied.value, sort })
    }
    const goToPage = (number: number) => visit(applied.value, number)

    function bookmarkChanged(id: number, saved: boolean) {
        const results = result.value
        if (saved || !applied.value.filters.saved_only || !results?.ids.includes(id)) return

        const { current_page, per_page } = results.meta
        const total = results.meta.total - 1
        const lastPage = Math.max(1, Math.ceil(total / per_page))
        if (current_page > lastPage) {
            goToPage(lastPage)
            return
        }

        results.ids = results.ids.filter(runId => runId !== id)
        results.items = results.items.filter(item => item.id !== id)
        results.cards = results.cards.filter(card => card.id !== id)
        results.meta = { ...results.meta, total, last_page: lastPage }

        // A later page may have a run to fill the vacant spot.
        const expectedCards = Math.min(per_page, total - (current_page - 1) * per_page)
        if (results.cards.length < expectedCards) void fetchResults(true)
    }

    function clear() {
        router.push({ url: route('dashboard.runs.index', {}, false), preserveScroll: true, preserveState: true })
    }

    watch([() => page.url, perPage], ([url, size], [previousUrl, previousSize]) => {
        if (url !== previousUrl || previousSize === undefined) {
            readLocation()
            return
        }
        if (!active.value) return

        // Keep the first run from the previous page in view when the grid changes.
        const meta = result.value?.meta
        const firstIndex = ((meta?.current_page ?? currentPage.value) - 1) * (meta?.per_page ?? previousSize)
        const nextPage = Math.floor(firstIndex / size) + 1
        if (nextPage === currentPage.value) {
            void fetchResults()
        } else {
            const params = runSearchParams(applied.value, searchTimezone.value, nextPage)
            router.replace({ url: `${route('dashboard.runs.index', {}, false)}?${params}`, preserveScroll: true, preserveState: true })
        }
    }, { immediate: true })
    onBeforeUnmount(() => request?.abort())
    return { active, applied, searchTimezone, loading, failed, result, selectActivity, applyFilters, setSort, goToPage, bookmarkChanged, clear, retry: fetchResults }
}
