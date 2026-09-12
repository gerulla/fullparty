import { reactive, watch } from 'vue'
import axios from 'axios'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import type { ResourceHolsterSettings } from '@/Types/GroupResources'

export function useResourceHolsters(groupSlug: () => string, settings: () => ResourceHolsterSettings | undefined, saved: () => void = () => {}) {
    const { t } = useI18n()
    const state = reactive({
        open: false, busy: false, error: '', collectionId: 'disabled',
        data: { collection_id: null, active_count: 0, resources: [] } as ResourceHolsterSettings,
    })
    watch(settings, value => { if (value) state.data = value }, { immediate: true })
    function open() {
        state.collectionId = state.data.collection_id === null ? 'disabled' : String(state.data.collection_id)
        state.error = ''
        state.open = true
    }
    async function save() {
        if (state.busy) return
        state.busy = true
        state.error = ''
        try {
            const response = await axios.put<{ data: ResourceHolsterSettings }>(route('groups.dashboard.resources.library.holsters.update', { group: groupSlug() }), {
                collection_id: state.collectionId === 'disabled' ? null : Number(state.collectionId),
            })
            state.data = response.data.data
            state.open = false
            saved()
        } catch (error) {
            state.error = axios.isAxiosError(error) && error.response?.status === 422
                ? error.response.data.errors?.collection_id?.[0] || t('groups.resources.holsters.save_failed')
                : t('groups.resources.holsters.save_failed')
        } finally { state.busy = false }
    }
    return { state, open, save }
}
