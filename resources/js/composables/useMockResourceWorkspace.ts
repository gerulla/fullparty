import { computed, onBeforeUnmount, onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController, ResourceWorkspaceState, WorkspaceDocument } from '@/Types/ResourceWorkspace'
import { createResourceWorkspaceFixtures, newWorkspaceDocument } from '@/utils/mockResourceData'
import { cloneDocument, collectionDescendants, filterWorkspaceResources, publishWorkspaceResource, validateWorkspaceDocument } from '@/utils/resourceWorkspace'

export function useMockResourceWorkspace(): ResourceWorkspaceController {
    const { t } = useI18n()
    const label = (key: string) => t(`groups.resources.workspace.${key}`)
    const state = reactive<ResourceWorkspaceState>({
        ...createResourceWorkspaceFixtures(), scope: 'all', selectedId: 'bridges', checked: ['bridges'], query: '',
        status: 'all', access: 'all', activity: 'all', mode: 'library', draft: null, summary: '',
        inspectorTab: 'resource', preview: null, previewOpen: false, historyOpen: false, error: '', collectionDialog: false,
        collectionForm: { id: '', name: '', parentId: null, icon: 'i-lucide-folder' },
        moveDialog: false, moveTarget: 'root', moveIds: [],
        confirmation: { open: false, title: '', description: '', label: '' },
    })
    let savedDraft = ''
    let confirmAction: (() => void) | null = null
    const selected = computed(() => state.resources.find(resource => resource.id === state.selectedId) ?? null)
    const visibleResources = computed(() => filterWorkspaceResources(state.resources, state.collections, state))
    const dirty = computed(() => state.mode === 'editor' && (JSON.stringify(state.draft) !== savedDraft || !!state.summary.trim()))
    const ask = (title: string, description: string, action: () => void, button = 'confirm') => {
        state.confirmation = { open: true, title: label(title), description: label(description), label: label(button) }
        confirmAction = action
    }
    const guard = (action: () => void) => {
        if (dirty.value) ask('unsaved_title', 'unsaved_description', action, 'discard')
        else action()
    }
    const leaveEditor = () => { state.mode = 'library'; state.draft = null; state.error = ''; state.summary = '' }
    const browseScope = (scope: string) => {
        leaveEditor(); state.scope = scope; state.checked = []; state.query = ''; state.status = 'all'; state.access = 'all'; state.activity = 'all'
        state.selectedId = visibleResources.value[0]?.id ?? null
    }
    const record = (id: string, summary: string) => {
        const resource = state.resources.find(item => item.id === id)
        if (!resource) return
        resource.updatedAt = new Date().toISOString()
        resource.history.unshift({ id: crypto.randomUUID(), author: 'Faust Gottes', authorAvatar: '/characters/char1.png', summary, at: resource.updatedAt })
    }
    const openEditor = (id: string, tab = 'resource') => {
        const resource = state.resources.find(item => item.id === id)
        if (!resource) return
        state.selectedId = id
        state.error = ''
        if (resource.status === 'pending') { state.error = label('pending_lock'); return }
        state.draft = cloneDocument(resource)
        savedDraft = JSON.stringify(state.draft)
        state.summary = ''
        state.inspectorTab = tab
        state.mode = 'editor'
    }
    const beforeUnload = (event: BeforeUnloadEvent) => { if (dirty.value) event.preventDefault() }
    onMounted(() => window.addEventListener('beforeunload', beforeUnload))
    onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))

    return {
        state,
        get visibleResources() { return visibleResources.value },
        get selected() { return selected.value },
        get dirty() { return dirty.value },
        browse(scope: string) {
            guard(() => browseScope(scope))
        },
        browseResource(id: string) {
            guard(() => {
                const resource = state.resources.find(item => item.id === id)
                if (!resource) return
                browseScope(resource.collectionId ?? 'all')
                state.selectedId = resource.id
            })
        },
        select(id: string) { state.selectedId = id; state.error = '' },
        edit(id: string, tab = 'resource') { guard(() => openEditor(id, tab)) },
        setCommandEnabled(id: string, enabled: boolean) {
            const resource = state.resources.find(item => item.id === id)
            if (!resource || resource.status === 'pending') return
            resource.embed.enabled = enabled
            resource.status = 'draft'
        },
        back() { guard(leaveEditor) },
        createResource() {
            guard(() => {
                const id = crypto.randomUUID()
                const folder = state.collections.some(item => item.id === state.scope) ? state.scope : null
                state.resources.push({ ...newWorkspaceDocument(folder), title: label('untitled'), id, status: 'draft', order: state.resources.length,
                    updatedAt: new Date().toISOString(), history: [], published: null })
                openEditor(id)
            })
        },
        save(submit = false) {
            const resource = selected.value
            if (!resource || !state.draft || resource.status === 'pending') return
            state.draft.embed.command = state.draft.embed.command.toLowerCase()
            const error = validateWorkspaceDocument(state.draft, state.resources, resource.id)
            if (error) { state.error = label(error); return }
            Object.assign(resource, cloneDocument(state.draft), { status: submit ? 'pending' : 'draft' })
            record(resource.id, state.summary.trim() || label(submit ? 'submitted_history' : 'saved_history'))
            savedDraft = JSON.stringify(state.draft)
            state.error = ''; state.summary = ''
            if (submit) leaveEditor()
        },
        publish(ids: string[]) {
            const pendingIds = ids.filter(id => state.resources.find(item => item.id === id)?.status === 'pending')
            state.resources = state.resources.map(resource => ids.includes(resource.id) ? publishWorkspaceResource(resource) : resource)
            pendingIds.forEach(id => record(id, label('published_history')))
            state.checked = []
        },
        discardPending(id: string) {
            ask('discard_pending', 'discard_pending_description', () => {
                const resource = state.resources.find(item => item.id === id)
                if (!resource || resource.status !== 'pending') return
                if (resource.published) Object.assign(resource, cloneDocument(resource.published), { status: 'published' })
                else resource.status = 'draft'
                record(id, label('discarded_history'))
            }, 'discard')
        },
        remove(id: string) {
            ask('delete_resource', 'delete_resource_description', () => {
                state.resources = state.resources.filter(item => item.id !== id)
                state.checked = state.checked.filter(item => item !== id)
                if (state.selectedId === id) { leaveEditor(); state.selectedId = visibleResources.value[0]?.id ?? null }
            }, 'delete')
        },
        reorder(id: string, offset: number) {
            const index = visibleResources.value.findIndex(item => item.id === id)
            const resource = visibleResources.value[index]
            const other = visibleResources.value[index + offset]
            if (resource && other) [resource.order, other.order] = [other.order, resource.order]
        },
        reorderBefore(id: string, targetId: string) {
            if (id === targetId) return
            const ordered = [...state.resources].sort((a, b) => a.order - b.order)
            const resource = ordered.find(item => item.id === id)
            if (!resource || !ordered.some(item => item.id === targetId)) return
            const remaining = ordered.filter(item => item.id !== id)
            remaining.splice(remaining.findIndex(item => item.id === targetId), 0, resource)
            remaining.forEach((item, index) => { item.order = index })
        },
        reorderCollection(id: string, offset: number) {
            const collection = state.collections.find(item => item.id === id)
            if (!collection) return
            const siblings = state.collections.filter(item => item.parentId === collection.parentId)
            const other = siblings[siblings.findIndex(item => item.id === id) + offset]
            if (!other) return
            const source = state.collections.indexOf(collection)
            const target = state.collections.indexOf(other)
            state.collections[source] = other
            state.collections[target] = collection
        },
        preview(document?: WorkspaceDocument) {
            const source = document ?? state.draft ?? selected.value
            if (source) { state.preview = cloneDocument(source); state.previewOpen = true }
        },
        openCollection(id?: string) {
            const collection = state.collections.find(item => item.id === id)
            state.collectionForm = collection ? { ...collection, icon: 'i-lucide-folder' } : { id: '', name: '', parentId: state.collections.some(item => item.id === state.scope) ? state.scope : null, icon: 'i-lucide-folder' }
            state.error = ''; state.collectionDialog = true
        },
        saveCollection() {
            const form = state.collectionForm
            if (!form.name.trim()) { state.error = label('name_required'); return }
            if (form.id && form.parentId && collectionDescendants(state.collections, form.id).includes(form.parentId)) {
                state.error = label('folder_cycle'); return
            }
            const existing = state.collections.find(item => item.id === form.id)
            if (existing) Object.assign(existing, { ...form, name: form.name.trim() })
            else state.collections.push({ ...form, id: crypto.randomUUID(), name: form.name.trim() })
            state.collectionDialog = false; state.error = ''
        },
        removeCollection(id: string) {
            if (state.resources.some(item => item.collectionId === id) || state.collections.some(item => item.parentId === id)) {
                state.error = label('folder_not_empty'); return
            }
            ask('delete_collection', 'delete_collection_description', () => {
                state.collections = state.collections.filter(item => item.id !== id)
                if (state.scope === id) { state.scope = 'all'; state.selectedId = visibleResources.value[0]?.id ?? null }
                state.collectionDialog = false
            }, 'delete')
        },
        openMove(ids: string[]) { state.moveIds = ids; state.moveTarget = 'root'; state.moveDialog = true },
        move() {
            state.resources.forEach(resource => {
                if (state.moveIds.includes(resource.id) && resource.status !== 'pending') resource.collectionId = state.moveTarget === 'root' ? null : state.moveTarget
            })
            state.moveDialog = false; state.checked = []
        },
        confirm() { const action = confirmAction; confirmAction = null; state.confirmation.open = false; action?.() },
    }
}
