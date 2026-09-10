import type { WorkspaceCollection, WorkspaceDocument, WorkspaceResource, WorkspaceTreeItem } from '../Types/ResourceWorkspace'

export function cloneDocument(resource: WorkspaceDocument): WorkspaceDocument {
    return JSON.parse(JSON.stringify({
        title: resource.title, description: resource.description, body: resource.body,
        collectionId: resource.collectionId, access: resource.access, activities: resource.activities,
        tags: resource.tags, author: resource.author, cover: resource.cover, embed: resource.embed,
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
    const visited = new Set<string>()
    const orderedResources = [...resources].sort((a, b) => a.order - b.order)
    const visit = (parentId: string | null, depth: number) => {
        for (const collection of collections.filter(item => item.parentId === parentId)) {
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
            rows.push({ kind: 'resource', resource, depth })
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
        if (filters.scope === 'drafts' && resource.status !== 'draft') return false
        if (filters.scope === 'pending' && resource.status !== 'pending') return false
        if (!['all', 'drafts', 'pending'].includes(filters.scope) && !folders.includes(resource.collectionId ?? '')) return false
        if (filters.status !== 'all' && resource.status !== filters.status) return false
        if (filters.access !== 'all' && resource.access !== filters.access) return false
        if (filters.activity !== 'all' && !resource.activities.includes(filters.activity)) return false
        const folder = collections.find(item => item.id === resource.collectionId)?.name ?? ''
        return !query || [resource.title, resource.description, folder, ...resource.tags, ...resource.activities]
            .join(' ').toLocaleLowerCase().includes(query)
    }).sort((a, b) => a.order - b.order)
}

export function validateWorkspaceDocument(document: WorkspaceDocument, resources: WorkspaceResource[], id: string): string | null {
    if (!document.title.trim()) return 'title_required'
    if (!document.embed.enabled && !document.embed.command) return null
    const command = document.embed.command.toLowerCase()
    if (command.length > 64 || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(command) || command === 'list') return 'command_invalid'
    if (resources.some(resource => resource.id !== id && [resource.embed.command, resource.published?.embed.command].includes(command))) return 'command_duplicate'
    return null
}

export function publishWorkspaceResource(resource: WorkspaceResource): WorkspaceResource {
    if (resource.status !== 'pending') return resource
    return { ...resource, status: 'published', published: cloneDocument(resource) }
}
