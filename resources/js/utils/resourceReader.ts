import type { ResourceReaderCollection } from '@/Types/GroupResources'

export function readerCollectionTree(collections: ResourceReaderCollection[]) {
    const result: (ResourceReaderCollection & { depth: number; total: number })[] = []
    const visited = new Set<number>()
    const totals = new Map(collections.map(item => [item.id, item.resource_count]))
    const byId = new Map(collections.map(item => [item.id, item]))
    for (const item of collections) {
        let parent = item.parent_id
        const ancestors = new Set([item.id])
        while (parent !== null && byId.has(parent) && !ancestors.has(parent)) {
            ancestors.add(parent)
            totals.set(parent, (totals.get(parent) ?? 0) + item.resource_count)
            parent = byId.get(parent)!.parent_id
        }
    }
    function visit(parent: number | null, depth: number) {
        for (const item of collections.filter(item => item.parent_id === parent)) {
            if (visited.has(item.id)) continue
            visited.add(item.id)
            result.push({ ...item, depth, total: totals.get(item.id) ?? 0 })
            visit(item.id, depth + 1)
        }
    }
    visit(null, 0)
    return result
}

export function readerCollectionPath(collections: ResourceReaderCollection[], id: number | null) {
    const path: ResourceReaderCollection[] = []
    const seen = new Set<number>()
    while (id !== null && !seen.has(id)) {
        seen.add(id)
        const collection = collections.find(item => item.id === id)
        if (!collection) break
        path.unshift(collection)
        id = collection.parent_id
    }
    return path
}

export function readerDate(value: string | null, locale: string): string {
    if (!value) return ''
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(locale, { year: 'numeric', month: 'short', day: 'numeric' })
}

export function readerDateTime(value: string, locale: string): string {
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' })
}
