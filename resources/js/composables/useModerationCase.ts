import { onBeforeUnmount, ref, watch, type Ref } from 'vue'
import axios from 'axios'
import { route } from 'ziggy-js'
import type { CaseDetail, ModerationAction } from '@/Types/Reports'

export function useModerationCase(initial: Ref<CaseDetail>) {
    const detail = ref(initial.value)
    const busy = ref(false)
    const error = ref('')
    let generation = 0
    watch(initial, value => { generation++; detail.value = value; busy.value = false; error.value = '' })
    onBeforeUnmount(() => { generation++ })

    async function request(endpoint?: string, payload: Record<string, unknown> = {}) {
        if (busy.value) return false
        const current = ++generation
        const id = detail.value.case.id
        busy.value = true; error.value = ''
        try {
            const response = endpoint
                ? await axios.post(route(endpoint, id), { ...payload, version: detail.value.case.version })
                : await axios.get(route('admin.reports.show', id), { headers: { Accept: 'application/json' } })
            if (current !== generation) return false
            detail.value = response.data
            return true
        } catch (cause) {
            if (current === generation) error.value = axios.isAxiosError(cause) && cause.response?.status === 409
                ? 'reports.errors.stale' : endpoint ? 'reports.errors.action' : 'reports.errors.load'
            return false
        } finally { if (current === generation) busy.value = false }
    }
    return { detail, busy, error, reload: () => request(),
        action: (action: ModerationAction, reason: string) => request('admin.reports.action', { action, reason }),
        feedback: (template: string, message: string) => request('admin.reports.feedback', { template, message }),
    }
}
