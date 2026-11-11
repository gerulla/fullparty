import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useToast } from '@nuxt/ui/composables'
import { useConfirmationModal } from '@/composables/useConfirmationModal'
import { emptyRichTextDocument } from '@/utils/richText'
import type { BozjaHolsterDraft, BozjaHolsterSummary, DrsWorkspaceData } from '@/Types/Bozja'

export function useDrsHolsterWorkspace(groupSlug: string, data: DrsWorkspaceData) {
    const { t } = useI18n()
    const toast = useToast()
    const confirmation = useConfirmationModal()
    const key = 'groups.index.content.delubrum_reginae_savage.holsters.'
    const records = ref([...data.holsters])
    const selectedId = ref<number | null>(null)
    const creating = ref(false)
    const busy = ref(false)
    const errors = ref<Record<string, string>>({})
    const draft = ref<BozjaHolsterDraft>({ name: {}, role: null, type: 'prepop', parent_holster_id: null, notes: '', guide: emptyRichTextDocument(), items: [] })
    const baseline = ref('')
    let confirmingLeave = false
    const selected = computed(() => records.value.find(h => h.id === selectedId.value) ?? null)
    const dirty = computed(() => JSON.stringify(draft.value) !== baseline.value)
    const capacity = computed(() => draft.value.items.reduce((sum, item) => sum + item.cache_weight * item.quantity, 0))
    const maximum = computed(() => selected.value?.max_capacity ?? 99)
    const refills = computed(() => records.value.filter(h => h.parent_holster_id === selectedId.value && h.type === 'refill'))

    function reset(holster: BozjaHolsterSummary | null, parent?: BozjaHolsterSummary) {
        selectedId.value = holster?.id ?? null
        creating.value = !holster
        draft.value = {
            name: Object.fromEntries(['en', 'de', 'fr', 'ja'].map(locale => [locale, String(holster?.name?.[locale] ?? '')])),
            role: holster?.role ?? parent?.role ?? null,
            type: holster?.type ?? (parent ? 'refill' : 'prepop'),
            parent_holster_id: holster?.parent_holster_id ?? parent?.id ?? null,
            notes: holster?.notes ?? '',
            guide: JSON.parse(JSON.stringify(holster?.guide ?? emptyRichTextDocument())),
            items: (holster?.items ?? []).map(item => ({ ...item })),
        }
        baseline.value = JSON.stringify(draft.value)
        errors.value = {}
    }

    async function canLeave() {
        if (busy.value || confirmingLeave) return false
        if (!dirty.value) return true
        confirmingLeave = true
        try {
            return Boolean(await confirmation.open({
                title: t(key + 'workspace.unsaved_title'), description: t(key + 'workspace.unsaved_description'),
                warningText: '', confirmLabel: t(key + 'workspace.discard'), severity: 'warning',
            }))
        } finally { confirmingLeave = false }
    }
    async function leaveEditor() {
        if (!await canLeave()) return false
        draft.value = JSON.parse(baseline.value)
        errors.value = {}
        return true
    }
    async function select(holster: BozjaHolsterSummary) {
        if (holster.id === selectedId.value) return !busy.value
        if (!await canLeave()) return false
        reset(holster)
        return true
    }
    async function create(parent?: BozjaHolsterSummary) {
        if (!await canLeave()) return false
        reset(null, parent)
        return true
    }
    function upsert(holster: BozjaHolsterSummary) {
        const index = records.value.findIndex(h => h.id === holster.id)
        if (index < 0) records.value.unshift(holster)
        else records.value[index] = holster
    }
    function failure(error: unknown, fallback: string) {
        const response = axios.isAxiosError(error) ? error.response?.data : null
        errors.value = Object.fromEntries(Object.entries(response?.errors ?? {}).map(([key, messages]) => [key, Array.isArray(messages) ? String(messages[0]) : String(messages)]))
        toast.add({ title: t('general.error'), description: Object.values(errors.value)[0] || t(key + fallback), color: 'error' })
    }
    function url(action: string, id?: number) {
        return route('groups.dashboard.content.delubrum-reginae-savage.holsters.' + action, { group: groupSlug, ...(id ? { bozjaHolster: id } : {}) })
    }
    async function save() {
        if (busy.value || capacity.value > maximum.value) return
        busy.value = true
        errors.value = {}
        const payload = {
            ...draft.value,
            parent_holster_id: draft.value.type === 'refill' ? draft.value.parent_holster_id : null,
            items: draft.value.items.map(({ id, quantity }) => ({ id, quantity })),
            ...(selected.value?.guide_needs_conversion ? { guide: undefined } : {}),
        }
        try {
            const response = selected.value
                ? await axios.put(url('update', selected.value.id), payload)
                : await axios.post(url('store'), payload)
            upsert(response.data.data)
            reset(response.data.data)
            toast.add({ title: t(key + 'saved'), color: 'success' })
        } catch (error) { failure(error, 'save_failed') }
        finally { busy.value = false }
    }
    async function toggle(holster: BozjaHolsterSummary) {
        if (busy.value) return
        busy.value = true
        try { upsert((await axios.patch(url('status.update', holster.id), { is_active: !holster.is_active })).data.data) }
        catch (error) { failure(error, 'status_update_failed') }
        finally { busy.value = false }
    }
    async function makeDefault(holster: BozjaHolsterSummary) {
        if (busy.value) return
        busy.value = true
        try {
            const response = await axios.patch(url('default.update', holster.id))
            records.value = records.value.map(h => ({ ...h, is_default: false }))
            upsert(response.data.data)
        } catch (error) { failure(error, 'default_update_failed') }
        finally { busy.value = false }
    }
    async function duplicate(holster: BozjaHolsterSummary) {
        if (!await canLeave()) return
        busy.value = true
        try {
            const response = await axios.post(url('clone', holster.id))
            upsert(response.data.data)
            reset(response.data.data)
            toast.add({ title: t(key + 'duplicated'), color: 'success' })
        } catch (error) { failure(error, 'duplicate_failed') }
        finally { busy.value = false }
    }
    async function remove(holster: BozjaHolsterSummary) {
        if (busy.value) return
        await confirmation.open({
            title: t(key + 'delete_modal.title', { name: holster.display_name }),
            description: t(key + 'delete_modal.description'), warningText: t(key + 'delete_modal.warning'),
            severity: 'error', confirmLabel: t('general.delete'), confirmIcon: 'i-lucide-trash-2',
            onConfirm: async ({ patch }) => {
                busy.value = true
                patch({ confirmLoading: true })
                try {
                    await axios.delete(url('destroy', holster.id))
                    records.value = records.value.filter(h => h.id !== holster.id)
                    if (selectedId.value === holster.id) reset(records.value.find(h => h.type === 'prepop') ?? records.value[0] ?? null)
                    return true
                } catch (error) { failure(error, 'delete_failed'); return false }
                finally { busy.value = false; patch({ confirmLoading: false }) }
            },
        })
    }

    reset(records.value.find(h => h.is_default) ?? records.value.find(h => h.type === 'prepop') ?? records.value[0] ?? null)
    const leaveMessage = () => t(key + 'workspace.unsaved_description')
    const stopNavigation = router.on('before', event => {
        if (!dirty.value && !busy.value) return
        event.preventDefault()
        const visit = event.detail.visit
        void leaveEditor().then(leave => {
            if (leave) router.visit(visit.url, { ...visit })
        })
    })
    // Inertia does not emit its cancellable `before` event for history traversal.
    // Capture first so cancelling Back keeps the mounted editor and its draft.
    let editorHistoryState = window.history.state
    let editorUrl = window.location.href
    watch([dirty, busy], ([hasChanges, isBusy]) => {
        if (hasChanges || isBusy) {
            editorHistoryState = window.history.state
            editorUrl = window.location.href
        }
    }, { flush: 'sync' })
    const beforeHistoryNavigation = (event: PopStateEvent) => {
        if (!dirty.value && !busy.value) return
        if (!busy.value && !confirmingLeave && window.confirm(leaveMessage())) return
        event.stopImmediatePropagation()
        window.history.pushState(editorHistoryState, '', editorUrl)
    }
    const beforeUnload = (event: BeforeUnloadEvent) => {
        if (dirty.value || busy.value) { event.preventDefault(); event.returnValue = '' }
    }
    window.addEventListener('beforeunload', beforeUnload)
    window.addEventListener('popstate', beforeHistoryNavigation, true)
    onBeforeUnmount(() => {
        stopNavigation()
        window.removeEventListener('beforeunload', beforeUnload)
        window.removeEventListener('popstate', beforeHistoryNavigation, true)
    })

    return reactive({ records, selected, selectedId, creating, busy, errors, draft, dirty, capacity, maximum, refills, select, create, leaveEditor, save, toggle, makeDefault, duplicate, remove })
}
