import { reactive } from 'vue'
import axios from 'axios'
import { route } from 'ziggy-js'
import type { GearsetCandidate, GearsetDisplay, GearsetImportResult, GearsetSnapshot } from '@/Types/XivGear'

export function useResourceGearsetImport(groupSlug: () => string, errorText: () => string) {
    const state = reactive({ open: false, url: '', loadedUrl: '', display: 'expanded' as GearsetDisplay, busy: false, error: '', sets: [] as GearsetCandidate[], snapshots: [] as GearsetSnapshot[], selected: [] as number[] })
    let request: AbortController | null = null
    function close() { request?.abort(); request = null; state.open = false; state.busy = false }
    function open(snapshots: GearsetSnapshot[] = [], display: GearsetDisplay = 'expanded') {
        close()
        Object.assign(state, { open: true, url: snapshots[0]?.sourceUrl ?? '', loadedUrl: snapshots[0]?.sourceUrl ?? '', display, error: '', sets: [], snapshots, selected: [] })
    }
    async function load(indices?: number[]) {
        request?.abort()
        const active = new AbortController()
        request = active
        state.busy = true; state.error = ''; state.snapshots = []
        if (indices === undefined) state.sets = []
        try {
            const { data } = await axios.post<GearsetImportResult>(route('groups.dashboard.resources.gearsets.import', { group: groupSlug() }), { url: state.url.trim(), set_indices: indices }, { signal: active.signal })
            if (request !== active) return
            state.loadedUrl = state.url.trim(); state.sets = data.sets; state.snapshots = data.snapshots
            if (indices === undefined) state.selected = data.sets.slice(0, 20).map(set => set.index)
        } catch (error) {
            if (request !== active || axios.isCancel(error)) return
            state.error = axios.isAxiosError(error) ? error.response?.data?.errors?.url?.[0] ?? errorText() : errorText()
        } finally { if (request === active) state.busy = false }
    }
    return { state, open, close, load }
}
