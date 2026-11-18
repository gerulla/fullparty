import axios from 'axios'
import { computed, shallowReactive } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useToast } from '@nuxt/ui/composables'
import type { RunCardData } from '@/Types/RunCards'

// A run may appear in several carousels or in an Inertia history entry.
const savedChanges = shallowReactive(new Map<string, boolean>())
const pendingChanges = shallowReactive(new Set<string>())

export function useRunBookmark(run: () => RunCardData) {
    const page = usePage<{ auth?: { user?: { id: number } | null } }>()
    const { t } = useI18n()
    const toast = useToast()
    const key = computed(() => `${page.props.auth?.user?.id}:${run().id}`)
    const saved = computed(() => savedChanges.get(key.value) ?? run().is_saved)
    const pending = computed(() => pendingChanges.has(key.value))
    const visible = computed(() => Boolean(page.props.auth?.user && (saved.value || run().can_bookmark)))

    async function toggle(): Promise<boolean | undefined> {
        if (!visible.value || pending.value) return

        const requestKey = key.value
        const params = { activity: run().id }
        pendingChanges.add(requestKey)
        try {
            const { data } = saved.value
                ? await axios.delete<{ saved: boolean }>(route('dashboard.runs.unsave', params))
                : await axios.post<{ saved: boolean }>(route('dashboard.runs.save', params))
            savedChanges.set(requestKey, data.saved)
            if (requestKey === key.value) return data.saved
        } catch {
            toast.add({ color: 'error', title: t('runs.browse.bookmark_error') })
        } finally {
            pendingChanges.delete(requestKey)
        }
    }

    return { saved, pending, visible, toggle }
}
