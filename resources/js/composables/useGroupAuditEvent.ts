import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useClipboard } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { useToast } from '@nuxt/ui/composables'
import { route } from 'ziggy-js'
import axios from 'axios'
import type { AuditLogEventRecord, AuditLogRowRecord } from '@/Types/Audit'

export function useGroupAuditEvent(groupSlug: () => string, rows: () => AuditLogRowRecord[]) {
    const page = usePage()
    const { t, locale } = useI18n()
    const toast = useToast()
    const { copy, copied } = useClipboard({ legacy: true })
    const event = ref<AuditLogEventRecord | null>(null)
    const loading = ref(false)
    const failed = ref(false)
    let controller: AbortController | null = null
    let requestId = 0

    const selectedId = computed(() => {
        const params = new URLSearchParams(page.url.split('?')[1]?.split('#')[0] ?? '')
        const value = params.get('event') ?? ''
        const id = Number(value)
        return /^[1-9]\d*$/.test(value) && Number.isSafeInteger(id) ? id : null
    })
    const selectedRow = computed(() => event.value ?? rows().find(row => row.id === selectedId.value))

    async function reload() {
        const id = ++requestId
        controller?.abort()
        event.value = null
        failed.value = false
        loading.value = selectedId.value !== null
        if (selectedId.value === null) return
        controller = new AbortController()
        try {
            const { data } = await axios.get<{ event: AuditLogEventRecord }>(route('groups.dashboard.audit-log.show', {
                group: groupSlug(), auditLog: selectedId.value, locale: locale.value,
            }), { signal: controller.signal, headers: { Accept: 'application/json' } })
            if (id === requestId) event.value = data.event
        } catch (error) {
            if (id === requestId && !axios.isCancel(error)) failed.value = true
        } finally {
            if (id === requestId) loading.value = false
        }
    }

    function select(id: number | null) {
        const url = new URL(page.url, window.location.origin)
        if (id === null) url.searchParams.delete('event')
        else url.searchParams.set('event', String(id))
        router.replace({ url: `${url.pathname}${url.search}${url.hash}`, preserveState: true, preserveScroll: true })
    }

    async function copyLink() {
        if (!selectedId.value) return
        const url = new URL(route('groups.dashboard.settings', { group: groupSlug(), locale: locale.value }), window.location.origin)
        url.searchParams.set('section', 'audit_log')
        url.searchParams.set('event', String(selectedId.value))
        try {
            await copy(url.href)
        } catch {
            toast.add({ title: t('audit_log.list.copy_failed'), color: 'error', icon: 'i-lucide-copy-x' })
        }
    }

    watch([selectedId, groupSlug, locale], reload, { immediate: true })
    onBeforeUnmount(() => { ++requestId; controller?.abort() })

    return { selectedId, selectedRow, event, loading, failed, copied, select, close: () => select(null), reload, copyLink }
}
