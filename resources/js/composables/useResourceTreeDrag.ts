import { computed, shallowRef } from 'vue'
import type { ResourceWorkspaceController, WorkspaceTreeItem } from '@/Types/ResourceWorkspace'
import { collectionDescendants } from '@/utils/resourceWorkspace'

export const resourceDragType = 'application/x-fullparty-resource'
type Source = { kind: 'collection' | 'resource'; id: string }
type Destination = { parentId: string | null; beforeId: string | null; key: string; edge: boolean }

export function useResourceTreeDrag(workspace: ResourceWorkspaceController) {
    const source = shallowRef<Source | null>(null)
    const target = shallowRef<Destination | null>(null)
    const sourceAllowed = computed(() => {
        const from = source.value
        if (!from) return false
        if (from.kind === 'collection') return workspace.state.collections.some(item => item.id === from.id)
        const resource = workspace.state.resources.find(item => item.id === from.id)
        return !!resource && !resource.isHome
    })
    const excludedParents = computed(() => new Set(source.value?.kind === 'collection'
        ? collectionDescendants(workspace.state.collections, source.value.id) : []))
    const key = (item: WorkspaceTreeItem) => item.kind === 'embed' ? `embed:${item.resource.id}:${item.index}` : `${item.kind}:${item.kind === 'collection' ? item.collection.id : item.resource.id}`
    function canDrag(item: WorkspaceTreeItem) {
        return item.kind !== 'embed' && !workspace.busy && !workspace.collectionActions.state.editing && (item.kind === 'collection' || !item.resource.isHome)
    }
    function start(event: DragEvent, item: WorkspaceTreeItem) {
        if (item.kind === 'embed' || !canDrag(item) || !event.dataTransfer) { event.preventDefault(); return }
        source.value = { kind: item.kind, id: item.kind === 'collection' ? item.collection.id : item.resource.id }
        event.dataTransfer.effectAllowed = 'move'
        event.dataTransfer.setData(resourceDragType, JSON.stringify(source.value))
    }
    function destination(event: DragEvent, item?: WorkspaceTreeItem): Destination | null {
        if (!item) return { parentId: null, beforeId: null, key: 'root', edge: false }
        if (item.kind === 'embed') return null
        if (item.kind === 'resource') {
            if (item.resource.isHome || source.value?.kind === 'collection') return null
            return { parentId: item.resource.collectionId, beforeId: item.resource.id, key: key(item), edge: true }
        }
        let edge = false
        if (source.value?.kind === 'collection') {
            const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
            edge = event.clientY - rect.top < Math.min(10, rect.height / 3)
        }
        return { parentId: edge ? item.collection.parentId : item.collection.id, beforeId: edge ? item.collection.id : null, key: key(item), edge: !!edge }
    }
    function valid(to: Destination) {
        return sourceAllowed.value && source.value?.id !== to.beforeId && !excludedParents.value.has(to.parentId ?? '')
    }
    function highlight(to: Destination | null) {
        // Native dragover repeats while stationary; only invalidate the tree for a changed destination.
        const previous = target.value
        if (previous?.key !== to?.key || previous?.edge !== to?.edge || previous?.parentId !== to?.parentId || previous?.beforeId !== to?.beforeId) {
            target.value = to
        }
    }
    function over(event: DragEvent, item?: WorkspaceTreeItem) {
        if (workspace.busy || workspace.collectionActions.state.editing || !event.dataTransfer?.types.includes(resourceDragType)) return
        const to = destination(event, item)
        highlight(to && (!source.value || valid(to)) ? to : null)
        if (target.value) { event.preventDefault(); event.dataTransfer.dropEffect = 'move' }
    }
    function end() { source.value = null; target.value = null }
    function drop(event: DragEvent, item?: WorkspaceTreeItem) {
        if (workspace.busy || workspace.collectionActions.state.editing) return
        try {
            const from = JSON.parse(event.dataTransfer?.getData(resourceDragType) ?? '') as Source
            if (!['collection', 'resource'].includes(from.kind) || typeof from.id !== 'string') return
            source.value = from
            const to = destination(event, item)
            if (!to || !valid(to)) return
            event.preventDefault()
            workspace.organize(from.kind, from.id, to.parentId, to.beforeId)
        } catch { /* Ignore unrelated or malformed drag payloads. */ }
        finally { end() }
    }
    function classes(item?: WorkspaceTreeItem) {
        const active = target.value?.key === (item ? key(item) : 'root')
        return { 'drop-inside': active && !target.value?.edge, 'drop-before': active && target.value?.edge }
    }
    return { canDrag, start, over, drop, end, classes }
}
