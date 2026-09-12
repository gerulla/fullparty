import axios from 'axios'
import { reactive } from 'vue'
import { route } from 'ziggy-js'
import type { ResourceCollectionData } from '@/Types/GroupResources'
import type { ResourceCollectionActions, ResourceCollectionOptions } from '@/Types/ResourceCollections'
import type { WorkspaceCollection } from '@/Types/ResourceWorkspace'
import { isValidCollectionName, workspaceCollection } from '@/utils/resourceWorkspaceData'

export function useResourceCollections(options: ResourceCollectionOptions): ResourceCollectionActions {
    const state = reactive<ResourceCollectionActions['state']>({ editing: null, iconCollectionId: null, busy: false, error: '' })
    const blocked = () => state.busy || options.blocked()
    const endpoint = (action: string, id: string) => route(`groups.dashboard.resources.collections.${action}`, { group: options.groupSlug(), collection: id })
    function report(error: unknown) {
        const errors = axios.isAxiosError(error) ? error.response?.data?.errors : null
        state.error = errors ? Object.values(errors).flat().join(' ') : options.label('request_failed')
    }
    function merge(collections: WorkspaceCollection[]) {
        const replacements = new Map(collections.map(item => [item.id, item]))
        const next = options.collections().map(item => replacements.get(item.id) ?? item)
        next.push(...collections.filter(item => !next.some(existing => existing.id === item.id)))
        options.replace(next.sort((a, b) => (a.order ?? 0) - (b.order ?? 0)))
    }
    return {
        state,
        changeIcon(id) {
            if (blocked() || state.editing || !options.collections().some(item => item.id === id)) return
            state.error = ''; state.iconCollectionId = id
        },
        closeIconPicker() { if (!state.busy) { state.iconCollectionId = null; state.error = '' } },
        async saveIcon(icon) {
            const id = state.iconCollectionId
            if (!id || blocked()) return
            state.busy = true; state.error = ''
            try {
                const { data } = await axios.put<{ data: ResourceCollectionData }>(endpoint('update', id), { icon })
                merge([workspaceCollection(data.data)]); state.iconCollectionId = null
            } catch (error) { report(error) }
            finally { state.busy = false }
        },
        create(parentId = null) {
            if (blocked() || state.editing) return
            if (parentId !== null && !options.collections().some(item => item.id === parentId)) return
            state.error = ''; state.editing = { id: null, parentId, name: options.label('new_collection_name') }
        },
        rename(id) {
            if (blocked() || state.editing) return
            const collection = options.collections().find(item => item.id === id)
            if (collection) { state.error = ''; state.editing = { id, parentId: collection.parentId, name: collection.name } }
        },
        cancel() { if (!state.busy) { state.editing = null; state.error = ''; options.cancelled() } },
        async save() {
            const edit = state.editing
            if (!edit || blocked()) return
            const name = edit.name.trim()
            if (!name) { state.error = options.label('name_required'); return }
            if (!isValidCollectionName(name)) { state.error = options.label('collection_name_invalid'); return }
            if (edit.id && options.collections().find(item => item.id === edit.id)?.name === name) { state.editing = null; state.error = ''; return }
            state.busy = true; state.error = ''
            let created: WorkspaceCollection | null = null
            try {
                const collection = edit.id
                    ? workspaceCollection((await axios.put<{ data: ResourceCollectionData }>(endpoint('update', edit.id), { name })).data.data)
                    : await options.create(name, edit.parentId)
                if (!collection) return
                merge([collection]); state.editing = null
                if (!edit.id) created = collection
            } catch (error) { report(error) }
            finally { state.busy = false }
            if (created) await options.created(created)
        },
        async remove(id) {
            if (blocked() || state.editing) return
            const collection = options.collections().find(item => item.id === id)
            if (!collection) return
            state.busy = true; state.error = ''
            try {
                await axios.delete(endpoint('destroy', id))
                options.replace(options.collections().filter(item => item.id !== id)); options.removed(collection)
            } catch (error) { report(error) }
            finally { state.busy = false }
        },
        async reorder(id, offset) {
            if (blocked() || state.editing) return
            state.busy = true; state.error = ''
            try {
                const { data } = await axios.post<{ data: ResourceCollectionData[] }>(endpoint('reorder', id), { offset })
                merge(data.data.map(workspaceCollection))
            } catch (error) { report(error) }
            finally { state.busy = false }
        },
    }
}
