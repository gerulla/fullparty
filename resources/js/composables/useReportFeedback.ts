import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'
import type { ReportFeedback } from '@/Types/Reports'

export function useReportFeedback() {
    const page = usePage()
    const userId = computed(() => (page.props.auth as { user?: { id: number } } | undefined)?.user?.id)
    const next = ref<ReportFeedback | null>(null)
    const count = ref(0)
    const busy = ref(false)
    const failed = ref(false)
    let requestGeneration = 0
    let loading = false
    let stopNavigation: (() => void) | undefined

    async function reload() {
        if (!userId.value || next.value || loading || busy.value) return
        const generation = requestGeneration
        loading = true
        try {
            const { data } = await axios.get(route('reports.feedback.pending'))
            if (generation === requestGeneration) { next.value = data.next; count.value = data.count }
        } catch { /* Retry on the next navigation or visit; never acknowledge a failed fetch. */ }
        finally { loading = false; if (generation !== requestGeneration) void reload() }
    }
    async function acknowledge() {
        if (!next.value || busy.value) return
        const generation = requestGeneration
        busy.value = true; failed.value = false
        try {
            const { data } = await axios.post(route('reports.feedback.acknowledge', next.value.id))
            if (generation === requestGeneration) { next.value = data.next; count.value = data.count }
        } catch { failed.value = true }
        finally { busy.value = false }
    }
    function visible() { if (document.visibilityState === 'visible') void reload() }
    watch(userId, () => { requestGeneration++; next.value = null; count.value = 0; void reload() })
    onMounted(() => {
        void reload()
        stopNavigation = router.on('finish', () => { void reload() })
        document.addEventListener('visibilitychange', visible)
    })
    onUnmounted(() => { requestGeneration++; stopNavigation?.(); document.removeEventListener('visibilitychange', visible) })
    return { next, count, busy, failed, acknowledge }
}
