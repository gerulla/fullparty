import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'
import { copyRunSearch, emptyRunSearch, hasRunSearch, readRunSearch, runSearchParams } from '@/utils/runBrowseSearch'
import type { RunDiscoveryLookups, RunSearchLookups } from '@/Types/RunDiscovery'

const blankLookups: RunSearchLookups = { activity_types: [], groups: [] }

export function useGlobalRunSearch() {
    const page = usePage<{
        auth?: { user?: { id: number } | null }
        locale?: { current: string }
        lookups?: RunDiscoveryLookups
    }>()
    const { displayTimeZone } = useDateTimeFormat()
    const onFindRuns = computed(() => page.component === 'Dashboard/Runs/Index')
    const pageLookups = computed(() => onFindRuns.value ? page.props.lookups : undefined)
    const appliedSearch = computed(() => {
        if (!pageLookups.value) return null
        const params = new URL(page.url, window.location.origin).searchParams
        const state = readRunSearch(params, pageLookups.value)
        return hasRunSearch(params, state) ? state : null
    })
    const fetched = shallowRef<RunSearchLookups | null>(null)
    const lookups = computed(() => pageLookups.value ?? fetched.value ?? blankLookups)
    const draft = ref(emptyRunSearch())
    const optionsLoading = ref(false)
    const optionsFailed = ref(false)
    let request: AbortController | null = null

    async function loadOptions() {
        if (pageLookups.value || fetched.value || request) return
        const pending = new AbortController()
        request = pending
        optionsLoading.value = true
        optionsFailed.value = false
        try {
            const response = await axios.get<RunSearchLookups>(route('dashboard.runs.search-options'), {
                signal: pending.signal,
            })
            if (!pending.signal.aborted) fetched.value = response.data
        } catch {
            if (!pending.signal.aborted) optionsFailed.value = true
        } finally {
            if (request === pending) {
                request = null
                optionsLoading.value = false
            }
        }
    }

    // A persistent topbar may outlive the current page, locale or signed-in user.
    watch([() => page.url, () => page.props.auth?.user?.id, () => page.props.locale?.current], () => {
        request?.abort()
        request = null
        fetched.value = null
        optionsLoading.value = false
        optionsFailed.value = false
        draft.value = appliedSearch.value ? copyRunSearch(appliedSearch.value) : emptyRunSearch()
    }, { immediate: true })

    function submit() {
        const state = {
            ...draft.value,
            filters: appliedSearch.value?.filters ?? draft.value.filters,
            sort: appliedSearch.value?.sort ?? draft.value.sort,
        }
        const timezone = displayTimeZone.value ?? Intl.DateTimeFormat().resolvedOptions().timeZone
        const url = `${route('dashboard.runs.index', {}, false)}?${runSearchParams(state, timezone)}`
        if (onFindRuns.value) {
            // Repeating a search refreshes results, even when the URL watcher has no change to observe.
            router.push({ url, preserveState: url !== page.url, preserveScroll: false })
        } else {
            router.visit(url)
        }
    }

    function backToBrowse() {
        if (appliedSearch.value) {
            router.push({ url: route('dashboard.runs.index', {}, false), preserveState: true, preserveScroll: false })
        }
    }

    onBeforeUnmount(() => request?.abort())
    return { onFindRuns, draft, appliedSearch, lookups, optionsLoading, optionsFailed, loadOptions, submit, backToBrowse }
}
