import { computed, onBeforeUnmount, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { route } from 'ziggy-js'
import type { ChangelogArticle, ChangelogSummary } from '@/Types/Changelog'

export function useChangelog() {
    const page = usePage()
    const { t } = useI18n()
    const isOpen = ref(false)
    const loading = ref(false)
    const historyLoading = ref(false)
    const error = ref('')
    const article = ref<ChangelogArticle | null>(null)
    const history = ref<ChangelogSummary[]>([])
    const historyOpen = ref(false)
    const hasMore = ref(false)
    const acknowledged = ref<number | null>(null)
    const latestId = computed(() => (page.props.changelog as { latest_id: number | null } | undefined)?.latest_id ?? null)
    const userId = computed(() => (page.props.auth as { user?: { id: number } } | undefined)?.user?.id)
    const unread = computed(() => {
        if (!latestId.value || acknowledged.value === latestId.value) return false
        if (userId.value) return Boolean((page.props.changelog as { unread: boolean } | undefined)?.unread)
        try { return localStorage.getItem('fullparty-changelog-read') !== String(latestId.value) } catch { return true }
    })
    let request: AbortController | null = null
    let historyRequest: AbortController | null = null
    let historyPage = 0

    async function acknowledge(entry: ChangelogArticle) {
        if (entry.id !== latestId.value) return
        try {
            if (userId.value) await axios.post(route('changelog.read', { changelogEntry: entry.id }))
            else localStorage.setItem('fullparty-changelog-read', String(entry.id))
            acknowledged.value = entry.id
        } catch { /* Leave it unread so a later visit can retry. */ }
    }

    async function load(id?: number) {
        request?.abort()
        const active = new AbortController()
        request = active
        loading.value = true
        error.value = ''
        article.value = null
        try {
            const { data } = await axios.get(id ? route('changelog.show', { changelogEntry: id }) : route('changelog.latest'), { signal: active.signal })
            if (active.signal.aborted) return
            article.value = data.entry
            if (data.entry) void acknowledge(data.entry)
        } catch (e) {
            if (!axios.isCancel(e)) error.value = t('changelog.load_error')
        } finally {
            if (request === active) loading.value = false
        }
    }

    function open() {
        isOpen.value = true
        historyOpen.value = false
        history.value = []
        historyPage = 0
        hasMore.value = false
        void load()
    }

    async function older() {
        historyOpen.value = true
        if (historyLoading.value || (historyPage > 0 && !hasMore.value)) return
        historyRequest?.abort()
        const active = new AbortController()
        historyRequest = active
        historyLoading.value = true
        error.value = ''
        try {
            const { data } = await axios.get(route('changelog.index'), { params: { page: historyPage + 1 }, signal: active.signal })
            if (active.signal.aborted) return
            history.value.push(...data.data)
            historyPage++
            hasMore.value = Boolean(data.next_page_url)
        } catch (e) {
            if (!axios.isCancel(e)) error.value = t('changelog.load_error')
        } finally {
            if (historyRequest === active) historyLoading.value = false
        }
    }

    function close() {
        isOpen.value = false
        request?.abort()
        historyRequest?.abort()
        historyLoading.value = false
    }
    onBeforeUnmount(close)
    return { isOpen, loading, error, article, history, historyOpen, historyLoading, hasMore, unread, open, close, load, older }
}
