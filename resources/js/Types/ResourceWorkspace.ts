import type { ResourceLibrary, ResourceReaderUrls } from './GroupResources'
import type { RichTextDocument } from './RichText'
import type { ResourceCollectionActions } from './ResourceCollections'
import type { ResourceFieldErrors } from './ResourceValidation'

export type WorkspaceAccess = 'everyone' | 'moderators' | 'admins'
export type WorkspaceStatus = 'draft' | 'published' | 'archived'
export type WorkspaceCollection = { id: string; name: string; parentId: string | null; icon: string; order?: number }
export type WorkspaceItemPosition = { id: string; parentId: string | null; order: number }
export type WorkspaceEmbed = {
    enabled: boolean; command: string; title: string; description: string; color: string
    url: string; author: string; authorUrl: string; authorIcon: string
    image: string; thumbnail: string; timestamp: string
    fields: { name: string; value: string; inline: boolean }[]
}
export type WorkspaceDocument = {
    title: string; description: string; body: RichTextDocument; collectionId: string | null
    access: WorkspaceAccess; activities: string[]; tags: string[]; author: string
    authorAvatar?: string
    authorCharacterId?: number | null; activityTypeIds?: number[]
    cover: string; embeds: WorkspaceEmbed[]
}
export type WorkspaceRevision = { id: string; kind?: 'edit' | 'publication'; author: string; authorAvatar?: string; summary: string; at: string }
export type WorkspaceRevisionSource = { id: string; document: WorkspaceDocument }
export type WorkspaceResource = WorkspaceDocument & {
    moderationHidden?: boolean
    holsterId?: number | null; inheritedBodyHtml?: string | null
    id: string; uuid?: string; isHome?: boolean; status: WorkspaceStatus; order: number; updatedAt: string; version: number; slug: string
    history: WorkspaceRevision[]; published: WorkspaceDocument | null; hasUnpublishedChanges?: boolean
    readerUrls?: ResourceReaderUrls | null
    isPinned: boolean
    canPublish: boolean
}
export type WorkspaceTreeItem =
    | { kind: 'collection'; collection: WorkspaceCollection; depth: number; hasChildren: boolean; count: number }
    | { kind: 'resource'; resource: WorkspaceResource; depth: number }
    | { kind: 'embed'; resource: WorkspaceResource; embed: WorkspaceEmbed; index: number; depth: number }
export type ResourceWorkspaceState = {
    collections: WorkspaceCollection[]; resources: WorkspaceResource[]
    scope: string; selectedId: string | null; checked: string[]; query: string
    status: string; access: string; activity: string; mode: 'library' | 'editor' | 'uploads'
    draft: WorkspaceDocument | null; summary: string; inspectorTab: string; embedIndex: number; editorPane: 'resource' | 'embed'
    error: string
    historyOpen: boolean
    sourceRevisionId: string | null
    saveDialog: boolean
    commandValidationAttempted: boolean
    commandErrors: Record<number, { value: string; message: string }>
    fieldErrors: ResourceFieldErrors
    autosaveError: boolean
    conflict: boolean
    createResourceDialog: boolean; createCollectionId: string
    moveDialog: boolean; moveTarget: string; moveIds: string[]
    confirmation: { open: boolean; title: string; description: string; label: string; severity?: 'warning' | 'error' }
}
export type ResourceWorkspaceController = {
    refresh: () => void
    state: ResourceWorkspaceState
    readonly visibleResources: WorkspaceResource[]
    readonly selected: WorkspaceResource | null
    readonly dirty: boolean
    readonly canPublish: boolean
    readonly creatingResource: boolean
    readonly creatingCollection: boolean
    readonly collectionActions: ResourceCollectionActions
    readonly loadingResource: boolean
    readonly busy: boolean
    readonly pinnedCount: number
    readonly pinLimit: number
    togglePin: (id: string) => void
    readonly autosaving: boolean
    readonly canRetrySave: boolean
    readonly library: ResourceLibrary | undefined
    readonly authors: { id?: number | null; name: string; avatar_url?: string | null }[]
    readonly activityOptions: { value: number; label: string }[]
    readonly accessLevels: WorkspaceAccess[]
    readonly activities: string[]
    embedPreview: (document: WorkspaceDocument, embed: WorkspaceEmbed) => WorkspaceEmbed
    commandError: (index: number) => string | undefined
    fieldError: (path: string) => string | undefined
    retrySave: () => Promise<boolean>
    reloadEditor: () => void
    archive: (id: string) => void
    unarchive: (id: string) => void
    confirmCreateResource: () => void
    browse: (scope: string) => void
    browseResource: (id: string) => void
    select: (id: string) => void
    edit: (id: string, tab?: string) => void
    openEmbed: (id: string, index: number) => void
    showResourceEditor: () => void
    addEmbed: () => void
    removeEmbed: (index: number) => void
    reorderBefore: (id: string, targetId: string) => void
    back: () => void
    createResource: (collectionId?: string | null) => void
    organize: (kind: 'collection' | 'resource', id: string, parentId: string | null, beforeId?: string | null) => void
    save: () => void
    confirmSave: () => Promise<void>
    useRevision: (id: string) => void
    publish: (ids: string[]) => void
    remove: (id: string) => void
    reorder: (id: string, offset: number) => void
    viewUrl: (resource?: WorkspaceResource) => string | undefined
    openMove: (ids: string[]) => void
    move: () => void
    confirm: () => Promise<boolean>
}
