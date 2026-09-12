import type { WorkspaceCollection } from './ResourceWorkspace'

export type ResourceCollectionEdit = { id: string | null; parentId: string | null; name: string }
export type ResourceCollectionActions = {
    state: { editing: ResourceCollectionEdit | null; iconCollectionId: string | null; busy: boolean; error: string }
    changeIcon: (id: string) => void
    closeIconPicker: () => void
    saveIcon: (icon: string | null) => Promise<void>
    create: (parentId?: string | null) => void
    rename: (id: string) => void
    cancel: () => void
    save: () => Promise<void>
    remove: (id: string) => Promise<void>
    reorder: (id: string, offset: -1 | 1) => Promise<void>
}
export type ResourceCollectionOptions = {
    groupSlug: () => string
    collections: () => WorkspaceCollection[]
    replace: (collections: WorkspaceCollection[]) => void
    blocked: () => boolean
    create: (name: string, parentId: string | null) => Promise<WorkspaceCollection | null>
    created: (collection: WorkspaceCollection) => void | Promise<void>
    removed: (collection: WorkspaceCollection) => void
    cancelled: () => void
    label: (key: string) => string
}
