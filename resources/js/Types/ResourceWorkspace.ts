export type WorkspaceAccess = 'everyone' | 'moderators' | 'admins'
export type WorkspaceStatus = 'draft' | 'pending' | 'published'
export type WorkspaceCollection = { id: string; name: string; parentId: string | null; icon: string }
export type WorkspaceEmbed = {
    enabled: boolean; command: string; title: string; description: string; color: string
    url: string; author: string; authorUrl: string; authorIcon: string
    image: string; thumbnail: string; timestamp: string
    fields: { name: string; value: string; inline: boolean }[]
}
export type WorkspaceDocument = {
    title: string; description: string; body: string; collectionId: string | null
    access: WorkspaceAccess; activities: string[]; tags: string[]; author: string
    cover: string; embed: WorkspaceEmbed
}
export type WorkspaceRevision = { id: string; author: string; authorAvatar?: string; summary: string; at: string }
export type WorkspaceResource = WorkspaceDocument & {
    id: string; status: WorkspaceStatus; order: number; updatedAt: string
    history: WorkspaceRevision[]; published: WorkspaceDocument | null
}
export type WorkspaceTreeItem =
    | { kind: 'collection'; collection: WorkspaceCollection; depth: number; hasChildren: boolean; count: number }
    | { kind: 'resource'; resource: WorkspaceResource; depth: number }
export type ResourceWorkspaceState = {
    collections: WorkspaceCollection[]; resources: WorkspaceResource[]
    scope: string; selectedId: string | null; checked: string[]; query: string
    status: string; access: string; activity: string; mode: 'library' | 'editor'
    draft: WorkspaceDocument | null; summary: string; inspectorTab: string
    preview: WorkspaceDocument | null; previewOpen: boolean; error: string
    historyOpen: boolean
    collectionDialog: boolean; collectionForm: WorkspaceCollection
    moveDialog: boolean; moveTarget: string; moveIds: string[]
    confirmation: { open: boolean; title: string; description: string; label: string }
}
export type ResourceWorkspaceController = {
    state: ResourceWorkspaceState
    readonly visibleResources: WorkspaceResource[]
    readonly selected: WorkspaceResource | null
    readonly dirty: boolean
    browse: (scope: string) => void
    browseResource: (id: string) => void
    select: (id: string) => void
    edit: (id: string, tab?: string) => void
    setCommandEnabled: (id: string, enabled: boolean) => void
    reorderBefore: (id: string, targetId: string) => void
    back: () => void
    createResource: () => void
    save: (submit?: boolean) => void
    publish: (ids: string[]) => void
    discardPending: (id: string) => void
    remove: (id: string) => void
    reorder: (id: string, offset: number) => void
    reorderCollection: (id: string, offset: number) => void
    preview: (document?: WorkspaceDocument) => void
    openCollection: (id?: string) => void
    saveCollection: () => void
    removeCollection: (id: string) => void
    openMove: (ids: string[]) => void
    move: () => void
    confirm: () => void
}
