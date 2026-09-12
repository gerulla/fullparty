import axios from 'axios'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import type { ResourceCollectionData, ResourceMutationData, ResourceRevisionData, ResourceSummaryData } from '@/Types/GroupResources'
import { workspaceResource } from '@/utils/resourceWorkspaceData'

export function useResourceMutations(group: () => string, activities: () => Map<number, string>, report: (error: unknown) => void) {
    const busy = ref(false)
    let lease: { id: string; version: number; token: string } | null = null
    let timer: ReturnType<typeof setInterval> | undefined
    let queue: Promise<unknown> = Promise.resolve()
    const tokenKey = (id: string) => `resource-edit:${group()}:${id}`
    function storedToken(id: string): string | null {
        try { return sessionStorage.getItem(tokenKey(id)) } catch { return null }
    }
    function rememberToken(id: string, token: string | null) {
        try { if (token) sessionStorage.setItem(tokenKey(id), token); else sessionStorage.removeItem(tokenKey(id)) } catch { /* Storage may be disabled. */ }
    }
    function serialize<T>(action: () => Promise<T>): Promise<T> {
        const next = queue.then(action)
        queue = next.catch(() => {})
        return next
    }
    function clearLease(forgetToken = true) { if (lease && forgetToken) rememberToken(lease.id, null); clearInterval(timer); timer = undefined; lease = null }
    function leaseRejected(error: unknown) {
        return axios.isAxiosError(error) && [403, 404, 409, 422].includes(error.response?.status ?? 0)
    }
    async function send(id: string, version: number, operation: string, payload: Record<string, unknown> = {}, recover = true): Promise<ResourceMutationData> {
        const current = lease?.id === id ? lease : null
        let data: { data: ResourceMutationData }
        try {
            const response = await axios.post<{ data: ResourceMutationData }>(route('groups.dashboard.resources.update', { group: group(), resource: id, operation }), {
                ...payload, version: current?.version ?? version, ...(current ? { editing_token: current.token } : {}),
            })
            data = response.data
        } catch (error) {
            if (recover && current && ['heartbeat', 'autosave', 'save', 'publish'].includes(operation) && axios.isAxiosError(error) && error.response?.status === 409) {
                // Never adopt a newer version: another editor's changes must remain a conflict.
                await send(id, current.version, 'acquire', {}, false)
                return send(id, current.version, operation, payload, false)
            }
            throw error
        }
        if (current) current.version = data.data.version
        if (current && data.data.editing_token) { current.token = data.data.editing_token; rememberToken(id, current.token) }
        if (operation === 'release') clearLease()
        return data.data
    }
    return {
        busy,
        async organize(kind: 'collection' | 'resource', id: string, parentId: string | null, beforeId?: string | null, version?: number) {
            const { data } = await axios.post<{ collections: ResourceCollectionData[]; resources: ResourceSummaryData[] }>(route('groups.dashboard.resources.organization', { group: group() }), {
                kind, id: Number(id), parent_id: parentId === null ? null : Number(parentId),
                before_id: beforeId ? Number(beforeId) : null, ...(version !== undefined ? { version } : {}),
            })
            return data
        },
        async revision(id: string, revisionId: string) {
            const { data } = await axios.get<{ data: ResourceRevisionData }>(route('groups.dashboard.resources.revisions.show', { group: group(), resource: id, revisionId }))
            return data.data
        },
        async acquire(id: string, version: number) {
            return serialize(async () => {
                const token = storedToken(id)
                const result = await send(id, version, 'acquire', token ? { editing_token: token } : {})
                lease = { id, version: result.version, token: result.editing_token! }
                rememberToken(id, lease.token)
                timer = setInterval(() => {
                    void serialize(async () => {
                        if (!lease) return
                        try { await send(lease.id, lease.version, 'heartbeat') }
                        catch (error) { if (leaseRejected(error)) { clearInterval(timer); timer = undefined }; report(error) }
                    })
                }, 60_000)
                return workspaceResource(result.resource!, activities())
            })
        },
        mutate(id: string, version: number, operation: string, payload: Record<string, unknown> = {}) {
            return serialize(async () => {
                const result = await send(id, version, operation, payload)
                return result.resource ? workspaceResource(result.resource, activities()) : null
            })
        },
        remove(id: string, version: number) {
            return serialize(async () => {
                const current = lease?.id === id ? lease : null
                await axios.delete(route('groups.dashboard.resources.destroy', { group: group(), resource: id }), {
                    data: { version: current?.version ?? version, ...(current ? { editing_token: current.token } : {}) },
                })
                if (current) clearLease()
            })
        },
        release() {
            clearInterval(timer)
            return serialize(async () => {
                if (!lease) return
                try { return await send(lease.id, lease.version, 'release') }
                catch (error) { clearLease(leaseRejected(error)); throw error }
            })
        },
        upload(file: File, altText = '', caption = '') {
            return serialize(async () => {
                if (!lease) throw new Error('resource_lease_required')
                const form = new FormData()
                form.append('image', file); form.append('alt_text', altText); form.append('caption', caption)
                form.append('resource_id', lease.id); form.append('version', String(lease.version)); form.append('editing_token', lease.token)
                const { data } = await axios.post<{ data: { url: string } }>(route('groups.dashboard.resources.images.store', { group: group() }), form)
                return data.data.url
            })
        },
    }
}
