import { onScopeDispose, ref, watch } from 'vue'
import axios from 'axios'
import type { ResourceReaderDocument, ResourceReaderHistoryEntry } from '@/Types/GroupResources'

export function useResourceReaderHistory(getResource: () => ResourceReaderDocument, getUrl: () => string) {
    const entries = ref<ResourceReaderHistoryEntry[]>([])
    const hasMore = ref(false)
    const loading = ref(false)
    const failed = ref(false)
    let request: AbortController | null = null

    watch(() => [getResource().id, getResource().history] as const, ([, history]) => {
        request?.abort()
        request = null
        entries.value = [...history.data]
        hasMore.value = history.has_more
        loading.value = false
        failed.value = false
    }, { immediate: true })
    onScopeDispose(() => request?.abort())

    async function loadMore() {
        if (loading.value || !hasMore.value) return
        const current = new AbortController()
        request = current
        loading.value = true
        failed.value = false
        try {
            const { data } = await axios.get<{ data: ResourceReaderHistoryEntry[] }>(getUrl(), {
                params: { before: entries.value.at(-1)?.id }, signal: current.signal,
            })
            if (current.signal.aborted) return
            const seen = new Set(entries.value.map(entry => entry.id))
            entries.value.push(...data.data.filter(entry => !seen.has(entry.id)))
            hasMore.value = false
        } catch {
            if (!current.signal.aborted) failed.value = true
        } finally {
            if (request === current) { loading.value = false; request = null }
        }
    }

    return { entries, hasMore, loading, failed, loadMore }
}
