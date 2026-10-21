import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'
import type { AccountFlag, AccountFlagsPageProps } from '@/Types/AccountFlags'
import { sortAccountFlags } from '@/utils/accountFlags'

export function useAccountFlags() {
    const page = usePage<AccountFlagsPageProps>()
    const flags = ref<AccountFlag[]>([])
    const userId = computed(() => page.props.auth?.user?.id)
    let request: AbortController | null = null
    let timer: ReturnType<typeof setInterval> | undefined
    let active = true
    let revision = 0

    watch([userId, () => page.props.account_flags], () => {
        revision++
        request?.abort()
        request = null
        flags.value = userId.value ? page.props.account_flags ?? [] : []
    }, { immediate: true })

    async function refresh() {
        if (!userId.value || request || document.visibilityState === 'hidden') return
        const controller = new AbortController()
        const startedAtRevision = revision
        request = controller
        try {
            const { data } = await axios.get<{ flags: AccountFlag[] }>(route('account.flags.index'), { signal: controller.signal })
            if (active && revision === startedAtRevision) flags.value = data.flags
        } catch (error) {
            // A connection problem must not hide an issue that still needs attention.
            if (active && revision === startedAtRevision && axios.isAxiosError(error)
                && [401, 403, 419].includes(error.response?.status ?? 0)) flags.value = []
        } finally {
            if (request === controller) request = null
        }
    }

    onMounted(() => {
        void refresh()
        timer = setInterval(() => { void refresh() }, 60_000)
        document.addEventListener('visibilitychange', refresh)
        window.addEventListener('focus', refresh)
    })
    onBeforeUnmount(() => {
        active = false
        request?.abort()
        clearInterval(timer)
        document.removeEventListener('visibilitychange', refresh)
        window.removeEventListener('focus', refresh)
    })

    return { flags: computed(() => sortAccountFlags(flags.value)) }
}
