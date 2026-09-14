import type { WorkspaceCollection, WorkspaceDocument, WorkspaceItemPosition, WorkspaceResource, WorkspaceTreeItem } from '../Types/ResourceWorkspace'

export const MAX_RESOURCE_EMBEDS = 15
export const MAX_RESOURCE_LINK_BUTTONS = 5

export function workspaceMovePositions(collections: WorkspaceCollection[], resources: WorkspaceResource[], kind: 'collection' | 'resource', id: string, parentId: string | null, beforeId?: string | null): WorkspaceItemPosition[] | null {
    if (parentId !== null && !collections.some(item => item.id === parentId)) return null
    if (kind === 'collection' && collectionDescendants(collections, id).includes(parentId ?? '')) return null
    if (kind === 'resource' && resources.some(item => item.id === id && item.isHome)) return null
    const items = kind === 'collection'
        ? collections.map(item => ({ id: item.id, parentId: item.parentId, order: item.order ?? 0 }))
        : resources.filter(item => !item.isHome).map(item => ({ id: item.id, parentId: item.collectionId, order: item.order }))
    if (!items.some(item => item.id === id)) return null
    const siblings = items.filter(item => item.parentId === parentId && item.id !== id)
        .sort((a, b) => a.order - b.order || a.id.localeCompare(b.id, 'en', { numeric: true }))
    const index = beforeId == null ? siblings.length : siblings.findIndex(item => item.id === beforeId)
    if (index < 0) return null
    siblings.splice(index, 0, { id, parentId, order: index })
    return siblings.map((item, order) => ({ ...item, order }))
}

export function cloneDocument(resource: WorkspaceDocument): WorkspaceDocument {
    return JSON.parse(JSON.stringify({
        title: resource.title, description: resource.description, body: resource.body,
        collectionId: resource.collectionId, access: resource.access, activities: resource.activities,
        tags: resource.tags, author: resource.author, authorAvatar: resource.authorAvatar, cover: resource.cover, embeds: resource.embeds,
        authorCharacterId: resource.authorCharacterId, activityTypeIds: resource.activityTypeIds,
    }))
}

export function collectionDescendants(collections: WorkspaceCollection[], id: string): string[] {
    const found = new Set([id])
    let changed = true
    while (changed) {
        changed = false
        for (const item of collections) {
            if (item.parentId && found.has(item.parentId) && !found.has(item.id)) {
                found.add(item.id)
                changed = true
            }
        }
    }
    return [...found]
}

export function workspaceCollectionPath(collections: WorkspaceCollection[], id: string | null): string {
    const names: string[] = []
    const visited = new Set<string>()
    let current = collections.find(item => item.id === id)
    while (current && !visited.has(current.id)) {
        names.unshift(current.name)
        visited.add(current.id)
        current = collections.find(item => item.id === current?.parentId)
    }
    return names.join(' / ')
}

export function buildWorkspaceTree(collections: WorkspaceCollection[], resources: WorkspaceResource[], collapsed: string[] = []): WorkspaceTreeItem[] {
    const rows: WorkspaceTreeItem[] = []
    const addResource = (resource: WorkspaceResource, depth: number) => {
        rows.push({ kind: 'resource', resource, depth })
        if (!collapsed.includes(`resource:${resource.id}`)) {
            resource.embeds.forEach((embed, index) => rows.push({ kind: 'embed', resource, embed, index, depth: depth + 1 }))
        }
    }
    resources.filter(item => item.isHome).forEach(resource => addResource(resource, 0))
    const visited = new Set<string>()
    const orderedResources = resources.filter(item => !item.isHome).sort((a, b) => a.order - b.order)
    const orderedCollections = [...collections].sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
    const visit = (parentId: string | null, depth: number) => {
        for (const collection of orderedCollections.filter(item => item.parentId === parentId)) {
            if (visited.has(collection.id)) continue
            visited.add(collection.id)
            const descendants = collectionDescendants(collections, collection.id)
            rows.push({
                kind: 'collection', collection, depth,
                hasChildren: collections.some(item => item.parentId === collection.id) || resources.some(item => item.collectionId === collection.id),
                count: resources.filter(item => descendants.includes(item.collectionId ?? '')).length,
            })
            if (!collapsed.includes(collection.id)) visit(collection.id, depth + 1)
        }
        for (const resource of orderedResources.filter(item => item.collectionId === parentId)) {
            addResource(resource, depth)
        }
    }
    visit(null, 0)
    return rows
}

export function filterWorkspaceResources(resources: WorkspaceResource[], collections: WorkspaceCollection[], filters: {
    scope: string; query: string; status: string; access: string; activity: string
}): WorkspaceResource[] {
    const folders = collectionDescendants(collections, filters.scope)
    const query = filters.query.trim().toLocaleLowerCase()
    return resources.filter(resource => {
        if ((resource.status === 'archived') !== (filters.scope === 'archived')) return false
        if (filters.scope === 'drafts' && !workspaceHasUnpublishedChanges(resource)) return false
        if (filters.scope === 'pinned' && !resource.isPinned) return false
        if (!['all', 'drafts', 'archived', 'pinned'].includes(filters.scope) && !folders.includes(resource.collectionId ?? '')) return false
        if (filters.status !== 'all' && resource.status !== filters.status) return false
        if (filters.access !== 'all' && resource.access !== filters.access) return false
        if (filters.activity !== 'all' && !resource.activities.includes(filters.activity)) return false
        const folder = collections.find(item => item.id === resource.collectionId)?.name ?? ''
        return !query || [resource.title, resource.description, folder, ...resource.tags, ...resource.activities]
            .join(' ').toLocaleLowerCase().includes(query)
    }).sort((a, b) => Number(!!b.isHome) - Number(!!a.isHome) || a.order - b.order)
}

export function validateWorkspaceDocument(document: WorkspaceDocument, resources: WorkspaceResource[], id: string): string | null {
    if (!document.title.trim()) return 'title_required'
    if (document.embeds.length > MAX_RESOURCE_EMBEDS) return 'embed_limit'
    return workspaceCommandErrors(document, resources, id).find(Boolean) ?? null
}

export function workspaceCommandErrors(document: WorkspaceDocument, resources: WorkspaceResource[], id: string): (string | null)[] {
    const names = new Set<string>()
    return document.embeds.map(embed => {
        const command = embed.command.toLowerCase()
        if (!command.trim()) return 'command_required'
        if (command === 'list') return 'command_reserved'
        if (command.length > 64 || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(command)) return 'command_invalid'
        if (names.has(command) || resources.some(resource => resource.id !== id && [...resource.embeds, ...(resource.published?.embeds ?? [])].some(item => item.command.toLowerCase() === command))) return 'command_duplicate'
        names.add(command)
        return null
    })
}

export function publishWorkspaceResource(resource: WorkspaceResource): WorkspaceResource {
    if (!workspaceHasUnpublishedChanges(resource)) return resource
    return { ...resource, status: 'published', published: cloneDocument(resource), hasUnpublishedChanges: false }
}

export function workspaceHasUnpublishedChanges(resource: WorkspaceResource, draft?: WorkspaceDocument): boolean {
    if (resource.status === 'archived') return false
    if (resource.status !== 'published') return true
    if (!draft && resource.hasUnpublishedChanges !== undefined) return resource.hasUnpublishedChanges
    if (!resource.published) return true
    const content = (document: WorkspaceDocument) => ({
        title: document.title, description: document.description, body: document.body, access: document.access,
        tags: document.tags, activities: document.activityTypeIds ?? document.activities,
        author: document.author, authorCharacterId: document.authorCharacterId ?? null, cover: document.cover,
        embeds: document.embeds.map(embed => ({
            command: embed.command, title: embed.title, description: embed.description, color: embed.color,
            url: embed.url, image: embed.image, thumbnail: embed.thumbnail, fields: embed.fields,
            buttons: embed.buttons ?? [],
        })),
    })
    return JSON.stringify(content(draft ?? resource)) !== JSON.stringify(content(resource.published))
}
