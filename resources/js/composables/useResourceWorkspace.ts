import axios from 'axios'
import { computed, nextTick, onBeforeUnmount, onMounted, provide, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceCollectionData, ResourceDetailData, ResourceLibrary, ResourceRevisionData, ResourceWorkspaceData } from '@/Types/GroupResources'
import type { ResourceWorkspaceController, ResourceWorkspaceState, WorkspaceDocument, WorkspaceItemPosition, WorkspaceResource, WorkspaceRevisionSource } from '@/Types/ResourceWorkspace'
import { cloneDocument, filterWorkspaceResources, MAX_RESOURCE_EMBEDS, workspaceCommandErrors, workspaceHasUnpublishedChanges, workspaceMovePositions } from '@/utils/resourceWorkspace'
import { workspaceCollection, workspaceDocument, workspaceEmbed, workspaceResource } from '@/utils/resourceWorkspaceData'
import { useResourceCreation } from './useResourceCreation'
import { useResourceMutations } from './useResourceMutations'
import { useResourceCollections } from './useResourceCollections'
import { resourceSavePayload } from '@/utils/resourceSavePayload'
import { resourceImageUploadKey } from './useResourceImageUpload'
import { resourceFieldPath, resourceFieldValue, validateResourceFields } from '@/utils/resourceValidation'
import { useResourceAutosave } from './useResourceAutosave'
import { useResourceNavigation } from './useResourceNavigation'

export function useResourceWorkspace(props: { groupSlug: string; collections: ResourceCollectionData[]; data: ResourceWorkspaceData; resource?: ResourceDetailData; library?: ResourceLibrary }, libraryChanged: () => void = () => {}): ResourceWorkspaceController {
    const { t, locale } = useI18n()
    const label = (key: string, params: Record<string, string | number> = {}) => t(`groups.resources.workspace.${key}`, params)
    const activities = computed(() => new Map(props.data.activities.map(item => [item.id, item.name[locale.value] || item.name.en || String(item.id)])))
    const api = useResourceCreation(() => props.groupSlug, () => activities.value)
    const state = reactive<ResourceWorkspaceState>({
        collections: props.collections.map(workspaceCollection), resources: props.data.resources.map(item => workspaceResource(item, activities.value)),
        scope: 'all', selectedId: null, checked: [], query: '', status: 'all', access: 'all', activity: 'all',
        mode: 'library', draft: null, summary: '', inspectorTab: 'resource', embedIndex: 0, editorPane: 'resource',
        historyOpen: false, sourceRevisionId: null, saveDialog: false, error: '',
        commandValidationAttempted: false, commandErrors: {},
        fieldErrors: {}, autosaveError: false, conflict: false,
        createResourceDialog: false, createCollectionId: '', moveDialog: false, moveTarget: 'root', moveIds: [],
        confirmation: { open: false, title: '', description: '', label: '' },
    })
    const loadingResource = ref(false)
    const loaded = new Set<string>()
    let selectionRequest = 0
    const savedDraft = ref('')
    let revisionSource: WorkspaceRevisionSource | null = null
    let recoveryConflict = false
    let recoveryBase = ''
    let autosaveFailure: { error: unknown; document: WorkspaceDocument } | null = null
    let confirmAction: (() => void | Promise<void>) | null = null
    const selected = computed(() => state.resources.find(item => item.id === state.selectedId) ?? null)
    const commandErrors = computed(() => state.draft ? workspaceCommandErrors(state.draft, state.resources, state.selectedId ?? '') : [])
    const visibleResources = computed(() => filterWorkspaceResources(state.resources, state.collections, state))
    const dirty = computed(() => state.mode === 'editor' && JSON.stringify(state.draft) !== savedDraft.value)
    const mutations = useResourceMutations(() => props.groupSlug, () => activities.value, report)
    const collectionActions = useResourceCollections({
        groupSlug: () => props.groupSlug, collections: () => state.collections, replace: collections => { state.collections = collections },
        blocked: () => busy.value, create: api.createCollection, label,
        async created(collection) {
            if (state.mode !== 'editor') await run(() => browse(collection.id))
        },
        cancelled: () => {},
        removed(collection) {
            if (state.scope === collection.id) state.scope = collection.parentId ?? 'all'
            if (state.draft?.collectionId === collection.id) state.draft.collectionId = null
        },
    })
    const busy = computed(() => mutations.busy.value || api.creatingResource.value || api.creatingCollection.value || collectionActions.state.busy)
    const backupKey = () => props.data.editor_user_id && state.selectedId ? `resource-draft:${props.data.editor_user_id}:${props.groupSlug}:${state.selectedId}` : null
    function backup() {
        const key = backupKey()
        if (!key || state.mode !== 'editor') return
        try {
            if (dirty.value) sessionStorage.setItem(key, JSON.stringify({ document: state.draft, base: recoveryConflict ? recoveryBase : savedDraft.value, source: revisionSource }))
            else sessionStorage.removeItem(key)
        } catch { /* Failed local recovery storage does not replace server autosave. */ }
    }
    const autosave = useResourceAutosave({
        identity: () => `${state.mode}:${state.selectedId}`,
        enabled: () => state.mode === 'editor' && !busy.value && !state.saveDialog && !state.confirmation.open && !state.conflict,
        changed: () => dirty.value,
        value: () => JSON.stringify(state.draft),
        backup,
        async save() {
            autosaveFailure = null
            if (!state.draft || !selected.value || !validateDraft(false)) return false
            const original = selected.value
            const document = cloneDocument(state.draft)
            const sent = JSON.stringify(document)
            try {
                const result = await mutations.mutate(original.id, original.version, 'autosave', resourceSavePayload(document, original, '', revisionSource))
                if (!result || state.selectedId !== original.id || state.mode !== 'editor') return false
                replace(result)
                const saved = cloneDocument(result)
                // The user can keep typing while this request is in flight.
                if (JSON.stringify(state.draft) === sent) state.draft = saved
                savedDraft.value = JSON.stringify(saved)
                state.autosaveError = false; state.error = ''; state.sourceRevisionId = null; revisionSource = null
                return true
            } catch (error) { autosaveFailure = { error, document }; return false }
        },
    })
    async function flushDraft(reportErrors = false) {
        if (busy.value) return false
        if (reportErrors && !validateDraft()) return false
        for (let attempt = 0; attempt < 3 && (dirty.value || autosave.saving.value); attempt++) {
            if (!await autosave.flush()) {
                if (reportErrors && autosaveFailure) { state.autosaveError = true; report(autosaveFailure.error, true, autosaveFailure.document) }
                return false
            }
        }
        return !dirty.value
    }
    useResourceNavigation(() => dirty.value || autosave.saving.value, flushDraft)
    provide(resourceImageUploadKey, async (file, alt, caption) => {
        if (busy.value) throw new Error('resource_busy')
        mutations.busy.value = true
        try { const url = await mutations.upload(file, alt, caption); libraryChanged(); return url }
        finally { mutations.busy.value = false }
    })
    function report(error: unknown, focus = true, submitted = state.draft) {
        const errors = axios.isAxiosError(error) ? error.response?.data?.errors : null
        if (axios.isAxiosError(error) && error.response?.status === 409 && state.mode === 'editor') state.conflict = true
        if (errors && state.mode === 'editor' && state.draft) {
            const remaining = []
            for (const [path, messages] of Object.entries(errors)) {
                const field = resourceFieldPath(path)
                const match = path.match(/^(?:content\.)?commands\.(\d+)\.name$/)
                const index = match ? Number(match[1]) : -1
                const message = Array.isArray(messages) ? messages.join(' ') : String(messages)
                state.fieldErrors[field] = { message, value: field === 'summary' ? state.summary : resourceFieldValue(submitted, field) }
                if (state.draft.embeds[index] && submitted?.embeds[index]?.command === state.draft.embeds[index].command) {
                    state.commandErrors[index] = { value: state.draft.embeds[index].command, message }
                }
                remaining.push(message)
            }
            state.error = [...new Set(remaining)].join(' ')
            if (focus) focusError()
            return
        }
        state.error = errors ? Object.values(errors).flat().join(' ') : axios.isAxiosError(error) && error.response?.status === 409 ? error.response.data.message : label('request_failed')
    }
    function resetCommandErrors() {
        state.commandValidationAttempted = false; state.commandErrors = {}; state.fieldErrors = {}
    }
    function showCommandError(index: number) {
        state.embedIndex = index; state.editorPane = 'embed'; state.inspectorTab = 'discord'; state.saveDialog = false
    }
    function fieldError(path: string): string | undefined {
        if (path === 'summary') return state.fieldErrors.summary?.value === state.summary ? state.fieldErrors.summary.message : undefined
        const error = state.fieldErrors[path]
        return error?.value === resourceFieldValue(state.draft, path) ? error.message : undefined
    }
    function focusError() {
        const path = Object.keys(state.fieldErrors).find(path => fieldError(path))
        if (!path) return
        const embed = path.match(/^commands\.(\d+)/)
        if (embed) showCommandError(Number(embed[1]))
        else if (path === 'commands') { state.inspectorTab = 'discord'; state.saveDialog = false }
        else if (path !== 'summary') {
            state.saveDialog = false; state.editorPane = 'resource'
            state.inspectorTab = ['collection_id', 'character_id', 'metadata_image_id'].includes(path) ? 'resource' : state.inspectorTab
        }
        void nextTick(() => {
            const field = [...document.querySelectorAll<HTMLElement>('[data-resource-field]')].find(element => element.dataset.resourceField === path)
            field?.querySelector<HTMLElement>('input, textarea, button, [contenteditable="true"]')?.focus()
            field?.scrollIntoView({ block: 'nearest' })
        })
    }
    function validateDraft(reportErrors = true): boolean {
        if (!state.draft || !selected.value) return false
        const errors = validateResourceFields(state.draft, state.resources, selected.value.id, label)
        const invalid = Object.keys(errors).length > 0
        if (!reportErrors) return !invalid
        state.commandValidationAttempted = true; state.commandErrors = {}
        state.fieldErrors = errors
        state.error = invalid ? label('validation.fix_fields') : ''
        if (invalid) focusError()
        return !invalid
    }
    async function run(action: () => void | Promise<void>): Promise<boolean> {
        if (busy.value) return false
        mutations.busy.value = true; state.error = ''
        selectionRequest++; loadingResource.value = false
        try { await action(); return true }
        catch (error) { report(error); return false }
        finally {
            mutations.busy.value = false
        }
    }
    function replace(resource: WorkspaceResource) {
        const index = state.resources.findIndex(item => item.id === resource.id)
        if (index < 0) state.resources.push(resource)
        else state.resources[index] = resource
        loaded.add(resource.id)
    }
    function guard(action: () => void | Promise<void>) {
        if (busy.value) return
        if (!dirty.value && !autosave.saving.value) { void run(action); return }
        void flushDraft().then(saved => { if (saved) void run(action) })
    }
    async function leaveEditor() {
        const released = await mutations.release()
        if (released) {
            const resource = state.resources.find(item => item.id === String(released.id))
            if (resource) resource.version = released.version
        }
        backup()
        state.mode = 'library'; state.draft = null; state.summary = ''; state.error = ''; selectionRequest++; loadingResource.value = false
        state.autosaveError = false; state.conflict = false
        state.sourceRevisionId = null; revisionSource = null; state.embedIndex = 0; state.inspectorTab = 'resource'; state.editorPane = 'resource'
        resetCommandErrors()
    }
    async function openEditor(id: string, tab = 'resource') {
        const resource = state.resources.find(item => item.id === id)
        if (!resource) return
        selectionRequest++; loadingResource.value = false
        state.selectedId = id
        const key = backupKey()
        let recovered: { document: WorkspaceDocument; base: string; source?: WorkspaceRevisionSource } | null = null
        try { recovered = key ? JSON.parse(sessionStorage.getItem(key) || 'null') : null } catch { /* Recovery storage is optional. */ }
        const current = await mutations.acquire(id, resource.version)
        replace(current)
        state.draft = cloneDocument(current); savedDraft.value = JSON.stringify(state.draft)
        resetCommandErrors()
        state.sourceRevisionId = null; revisionSource = null
        state.mode = 'editor'; state.inspectorTab = tab; state.editorPane = 'resource'; state.error = ''
        state.conflict = false; state.autosaveError = false
        recoveryConflict = false
        if (recovered?.document) {
            state.conflict = recovered.base !== savedDraft.value
            recoveryConflict = state.conflict
            recoveryBase = recovered.base
            state.draft = recovered.document; revisionSource = recovered.source ?? null; state.sourceRevisionId = recovered.source?.id ?? null
            state.error = label(state.conflict ? 'autosave_conflict' : 'draft_recovered')
        }
    }
    async function loadRevision(id: string) {
        const resource = selected.value
        if (state.mode !== 'editor' || !resource || !resource.history.some(item => item.id === id && item.kind !== 'publication')) return
        let revision: ResourceRevisionData | undefined
        if (!await run(async () => { revision = await mutations.revision(resource.id, id) }) || !revision) return
        const snapshot = revision.snapshot
        guard(() => {
            if (state.mode !== 'editor' || state.selectedId !== resource.id) return
            const collectionId = snapshot.collection_id === null ? null : state.collections.some(item => item.id === String(snapshot.collection_id)) ? snapshot.collection_id! : resource.collectionId === null ? null : Number(resource.collectionId)
            const document = cloneDocument(workspaceDocument(snapshot, collectionId, activities.value))
            revisionSource = { id, document }
            state.draft = cloneDocument(document); state.sourceRevisionId = id; state.summary = ''; state.saveDialog = false; state.embedIndex = 0; state.editorPane = 'resource'
            resetCommandErrors()
        })
    }
    async function select(id: string, after?: () => void) {
        const request = ++selectionRequest
        if (state.selectedId !== id) { state.embedIndex = 0; state.inspectorTab = 'resource' }
        state.selectedId = id; state.error = ''
        if (loaded.has(id)) { loadingResource.value = false; after?.(); return }
        loadingResource.value = true
        try {
            const resource = await api.load(id)
            if (request !== selectionRequest) return
            const index = state.resources.findIndex(item => item.id === id)
            if (index >= 0) state.resources[index] = resource
            loaded.add(id)
            if (request === selectionRequest) after?.()
        } catch (error) { if (request === selectionRequest) report(error) }
        finally { if (request === selectionRequest) loadingResource.value = false }
    }
    async function browse(scope: string) {
        await leaveEditor(); state.scope = scope; state.checked = []; state.query = ''; state.status = 'all'; state.access = 'all'; state.activity = 'all'
        if (scope === 'uploads') state.mode = 'uploads'
        state.selectedId = null
    }
    async function create(collectionId: string | null) {
        if (collectionId !== null && !state.collections.some(item => item.id === collectionId)) { state.error = label('collection_required'); return }
        state.error = ''
        try {
            const resource = await api.create(collectionId, label('untitled'))
            if (!resource) return
            state.resources.push(resource); loaded.add(resource.id)
            state.createResourceDialog = false; state.scope = collectionId ?? 'all'; state.checked = []
            await openEditor(resource.id)
        } catch (error) { report(error) }
    }
    function applyPositions(kind: 'collection' | 'resource', positions: WorkspaceItemPosition[]) {
        for (const position of positions) {
            if (kind === 'collection') {
                const collection = state.collections.find(item => item.id === position.id)
                if (collection) { collection.parentId = position.parentId; collection.order = position.order }
            } else {
                const resource = state.resources.find(item => item.id === position.id)
                if (resource) { resource.collectionId = position.parentId; resource.order = position.order }
            }
        }
    }
    async function organize(kind: 'collection' | 'resource', id: string, parentId: string | null, beforeId?: string | null) {
        const positions = workspaceMovePositions(state.collections, state.resources, kind, id, parentId, beforeId)
        if (!positions) return
        const previous = kind === 'collection'
            ? state.collections.map(item => ({ id: item.id, parentId: item.parentId, order: item.order ?? 0 }))
            : state.resources.map(item => ({ id: item.id, parentId: item.collectionId, order: item.order }))
        const resource = state.resources.find(item => item.id === id)
        applyPositions(kind, positions)
        try {
            if (kind === 'resource' && state.mode === 'editor' && state.selectedId === id) await leaveEditor()
            const data = await mutations.organize(kind, id, parentId, beforeId, kind === 'resource' ? resource?.version : undefined)
            state.collections = data.collections.map(workspaceCollection)
            for (const summary of data.resources) {
                const current = state.resources.find(item => item.id === String(summary.id))
                if (current) {
                    current.collectionId = summary.collection_id === null ? null : String(summary.collection_id)
                    current.order = summary.sort_order; current.version = summary.version
                }
            }
        } catch (error) {
            applyPositions(kind, previous)
            throw error
        }
    }
    async function persist() {
        if (!state.draft || !selected.value) return
        const original = selected.value
        const result = await mutations.mutate(original.id, original.version, 'save', resourceSavePayload(cloneDocument(state.draft), original, state.summary.trim(), revisionSource))
        if (!result) return
        replace(result); state.saveDialog = false; state.summary = ''
        resetCommandErrors()
        state.sourceRevisionId = null; revisionSource = null
        state.draft = cloneDocument(result); savedDraft.value = JSON.stringify(state.draft)
        state.autosaveError = false; state.conflict = false; backup()
    }
    function confirmDelete(id: string) {
        if (busy.value) return
        const resource = state.resources.find(item => item.id === id)
        if (!resource || resource.isHome) return
        state.error = ''
        state.confirmation = {
            open: true, title: label('delete_resource'),
            description: `${resource.title}: ${label('delete_resource_description')}`,
            label: label('delete'), severity: 'error',
        }
        confirmAction = async () => {
            await mutations.remove(id, resource.version)
            libraryChanged()
            selectionRequest++; loaded.delete(id)
            state.resources = state.resources.filter(item => item.id !== id); state.checked = state.checked.filter(value => value !== id)
            if (state.selectedId === id) { const key = backupKey(); if (key) sessionStorage.removeItem(key); state.mode = 'library'; await leaveEditor(); state.selectedId = null }
        }
    }
    const beforeUnload = (event: BeforeUnloadEvent) => { backup(); if (dirty.value || autosave.saving.value) { event.preventDefault(); event.returnValue = '' } }
    onMounted(() => window.addEventListener('beforeunload', beforeUnload))
    onBeforeUnmount(() => { selectionRequest++; window.removeEventListener('beforeunload', beforeUnload); void mutations.release().catch(() => {}) })
    if (props.resource) {
        const resource = workspaceResource(props.resource, activities.value)
        state.resources = state.resources.filter(item => item.id !== resource.id).concat(resource)
        loaded.add(resource.id); void run(() => openEditor(resource.id))
    }

    return {
        state,
        get selected() { return selected.value }, get visibleResources() { return visibleResources.value }, get dirty() { return dirty.value },
        get canPublish() { return !!selected.value?.canPublish && !dirty.value && !autosave.saving.value && !state.conflict },
        get creatingResource() { return api.creatingResource.value }, get creatingCollection() { return api.creatingCollection.value },
        collectionActions,
        get loadingResource() { return loadingResource.value }, get authors() { return props.data.authors }, get activities() { return [...activities.value.values()] },
        get busy() { return busy.value },
        get pinnedCount() { return state.resources.filter(item => item.isPinned).length },
        get pinLimit() { return props.data.pin_limit },
        togglePin(id) {
            guard(async () => {
                const resource = state.resources.find(item => item.id === id)
                if (!resource || resource.isHome || (resource.status === 'archived' && !resource.isPinned)) return
                const result = await mutations.mutate(id, resource.version, 'pin', { is_pinned: !resource.isPinned })
                if (result) replace(result)
            })
        },
        get autosaving() { return autosave.saving.value },
        get canRetrySave() { return !recoveryConflict },
        fieldError,
        async retrySave() { if (recoveryConflict) return false; state.conflict = false; return flushDraft(true) },
        reloadEditor() {
            const id = state.selectedId
            if (!id || busy.value) return
            state.confirmation = { open: true, title: label('reload_latest'), description: label('reload_latest_warning'), label: label('discard'), severity: 'warning' }
            confirmAction = async () => {
                const key = backupKey(); if (key) sessionStorage.removeItem(key)
                state.mode = 'library'
                try { await leaveEditor() } catch { /* Reload explicitly discards the old editing lease. */ }
                const current = await api.load(id); replace(current); await openEditor(id)
            }
        },
        get library() { return props.library },
        commandError(index) {
            const error = fieldError(`commands.${index}.name`)
            if (error) return error
            const localError = state.commandValidationAttempted ? commandErrors.value[index] : null
            if (localError) return label(localError)
            const serverError = state.commandErrors[index]
            return serverError && serverError.value === state.draft?.embeds[index]?.command ? serverError.message : undefined
        },
        embedPreview(document, embed) {
            const resource = state.resources.find(item => item === document) ?? selected.value
            const context = props.data.embed_context
            return {
                ...embed, author: document.title, authorIcon: context?.group_icon_url ?? '',
                authorUrl: props.library?.visibility === 'public' && document.access === 'everyone' && resource && context
                    ? `${context.public_base_url.replace(/\/$/, '')}/${encodeURIComponent(resource.uuid ?? resource.slug)}` : '',
            }
        },
        get activityOptions() { return [...activities.value].map(([value, label]) => ({ value, label })) },
        get accessLevels() { return props.data.access_levels.map(level => level === 'admin' ? 'admins' as const : level === 'moderator' ? 'moderators' as const : 'everyone' as const) },
        browse(scope) { guard(() => browse(scope)) },
        browseResource(id) {
            if (state.mode === 'editor' && state.selectedId === id) { state.editorPane = 'resource'; return }
            guard(async () => { await browse(state.resources.find(item => item.id === id)?.collectionId ?? 'all'); await select(id) })
        },
        select(id) { if (!busy.value) void select(id) },
        edit(id, tab) { if (state.resources.find(item => item.id === id)?.status === 'archived') return; guard(async () => { await leaveEditor(); const current = await api.load(id); replace(current); await openEditor(id, tab) }) },
        openEmbed(id, index) {
            if (busy.value) return
            if (state.mode === 'editor' && state.selectedId === id && state.draft?.embeds[index]) {
                state.embedIndex = index; state.inspectorTab = 'discord'; state.editorPane = 'embed'; return
            }
            guard(async () => {
                await leaveEditor()
                const current = await api.load(id)
                replace(current)
                if (!current.embeds[index]) return
                await openEditor(id, 'discord')
                state.embedIndex = index
                state.editorPane = 'embed'
            })
        },
        showResourceEditor() { if (!busy.value) state.editorPane = 'resource' },
        addEmbed() {
            if (busy.value || state.mode !== 'editor' || !state.draft || state.draft.embeds.length >= MAX_RESOURCE_EMBEDS) return
            state.draft.embeds.push({ ...workspaceEmbed(), title: state.draft.title })
            state.embedIndex = state.draft.embeds.length - 1; state.inspectorTab = 'discord'; state.editorPane = 'embed'
        },
        removeEmbed(index) {
            if (busy.value || state.mode !== 'editor' || !state.draft?.embeds[index]) return
            const embed = state.draft.embeds[index]
            state.confirmation = { open: true, title: label('delete_embed'), description: label('delete_embed_description'), label: label('delete'), severity: 'error' }
            confirmAction = () => {
                const currentIndex = state.draft?.embeds.indexOf(embed) ?? -1
                if (currentIndex < 0 || !state.draft) return
                state.draft.embeds.splice(currentIndex, 1)
                state.commandErrors = {}
                if (currentIndex < state.embedIndex) state.embedIndex--
                state.embedIndex = Math.max(0, Math.min(state.embedIndex, state.draft.embeds.length - 1))
                if (!state.draft.embeds.length) state.editorPane = 'resource'
            }
        },
        back() { guard(leaveEditor) },
        createResource(parentId) {
            guard(async () => {
                const collectionId = parentId === undefined ? state.collections.find(item => item.id === state.scope)?.id ?? null : parentId
                await leaveEditor()
                await create(collectionId)
            })
        },
        confirmCreateResource() { void run(() => create(state.createCollectionId)) },
        viewUrl(resource = selected.value ?? undefined) {
            const urls = resource?.readerUrls
            if (!urls) return undefined
            return props.library?.visibility === 'public' && urls.public ? urls.public : urls.group
        },
        save() {
            if (busy.value || autosave.saving.value || !state.draft || !selected.value || state.conflict) return
            if (!validateDraft()) return
            state.error = ''; state.summary = ''
            state.saveDialog = true
        },
        async confirmSave() {
            if (busy.value || !validateDraft()) return
            if (!state.summary.trim() || state.summary.length > 300 || /[\r\n]/.test(state.summary)) {
                state.fieldErrors.summary = { message: label('summary_required'), value: state.summary }; return
            }
            await run(persist)
        },
        useRevision(id) { void loadRevision(id) },
        publish(ids) {
            if (busy.value || autosave.saving.value || state.conflict) return
            if (dirty.value && ids.includes(state.selectedId ?? '')) { state.error = label('save_before_publish'); return }
            void run(async () => {
                const resources: WorkspaceResource[] = []
                for (const id of [...new Set(ids)]) {
                    let resource = state.resources.find(item => item.id === id)
                    if (!resource || !workspaceHasUnpublishedChanges(resource)) continue
                    if (!loaded.has(id)) { resource = await api.load(id); replace(resource) }
                    resources.push(resource)
                }
                if (!resources.length) return
                if (resources.some(resource => !resource.canPublish)) { state.error = label('save_before_publish'); return }
                state.confirmation = { open: true, title: label('publish'), description: label('publish_confirmation'), label: label('publish') }
                const remaining = resources.map(resource => resource.id)
                confirmAction = async () => {
                    if (dirty.value && remaining.includes(state.selectedId ?? '')) throw new Error('unsaved_resource')
                    for (const id of [...remaining]) {
                        const resource = state.resources.find(item => item.id === id)!
                        const result = await mutations.mutate(id, resource.version, 'publish')
                        if (result) {
                            replace(result)
                            if (state.mode === 'editor' && state.selectedId === id) {
                                state.draft = cloneDocument(result); savedDraft.value = JSON.stringify(state.draft)
                            }
                        }
                        remaining.splice(remaining.indexOf(id), 1)
                        state.checked = state.checked.filter(value => value !== id)
                    }
                }
            })
        },
        remove(id) { confirmDelete(id) },
        archive(id) {
            const resource = state.resources.find(item => item.id === id)
            if (!resource || resource.isHome || resource.status === 'archived') return
            if (state.mode === 'editor' && (dirty.value || autosave.saving.value)) { void flushDraft().then(saved => { if (saved) this.archive(id) }); return }
            state.confirmation = { open: true, title: label('archive_resource'), description: label('archive_warning'), label: label('archive'), severity: 'warning' }
            confirmAction = async () => {
                if (state.mode === 'editor') await leaveEditor()
                const result = await mutations.mutate(id, resource.version, 'archive')
                if (result) replace(result)
                state.selectedId = null; state.checked = state.checked.filter(item => item !== id)
            }
        },
        unarchive(id) {
            void run(async () => {
                const resource = state.resources.find(item => item.id === id)
                if (!resource || resource.status !== 'archived') return
                const result = await mutations.mutate(id, resource.version, 'unarchive')
                if (result) replace(result)
                state.selectedId = null
            })
        },
        organize(kind, id, parentId, beforeId) { guard(() => organize(kind, id, parentId, beforeId)) },
        reorder(id, offset) {
            const resource = state.resources.find(item => item.id === id)
            if (!resource || resource.isHome) return
            const siblings = state.resources.filter(item => item.collectionId === resource.collectionId && !item.isHome).sort((a, b) => a.order - b.order)
            const index = siblings.findIndex(item => item.id === id)
            if (index + offset < 0 || index + offset >= siblings.length) return
            const before = siblings[index + (offset > 0 ? 2 : -1)]?.id ?? null
            guard(() => organize('resource', id, resource.collectionId, before))
        },
        reorderBefore(id, targetId) {
            const target = state.resources.find(item => item.id === targetId)
            if (target && !target.isHome && id !== targetId) guard(() => organize('resource', id, target.collectionId, targetId))
        },
        openMove(ids) {
            state.moveIds = ids.filter(id => state.resources.some(item => item.id === id && !item.isHome))
            if (!state.moveIds.length) return
            state.moveTarget = 'root'; state.moveDialog = true
        },
        move() { guard(async () => { for (const id of state.moveIds) await organize('resource', id, state.moveTarget === 'root' ? null : state.moveTarget); state.moveDialog = false }) },
        async confirm() {
            if (!confirmAction) return false
            const success = await run(confirmAction)
            if (success) { confirmAction = null; state.confirmation.open = false }
            return success
        },
    }
}
