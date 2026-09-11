import axios from 'axios'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import type { ResourceCollectionData, ResourceDetailData } from '@/Types/GroupResources'
import { collectionSlug, untitledResourcePayload, workspaceCollection, workspaceResource } from '@/utils/resourceWorkspaceData'

export function useResourceCreation(groupSlug: () => string, activities: () => Map<number, string>) {
    const creatingResource = ref(false)
    const creatingCollection = ref(false)
    const requests = new Map<string, Promise<ReturnType<typeof workspaceResource>>>()
    return {
        creatingResource, creatingCollection,
        async create(collectionId: string | null, title: string) {
            if (creatingResource.value) return null
            creatingResource.value = true
            try {
                const { data } = await axios.post<{ data: ResourceDetailData }>(route('groups.dashboard.resources.store', { group: groupSlug() }), untitledResourcePayload(collectionId, title, crypto.randomUUID()))
                return workspaceResource(data.data, activities())
            } finally { creatingResource.value = false }
        },
        async createCollection(name: string, parentId: string | null) {
            if (creatingCollection.value) return null
            creatingCollection.value = true
            try {
                const { data } = await axios.post<{ data: ResourceCollectionData }>(route('groups.dashboard.resources.collections.store', { group: groupSlug() }), {
                    name, parent_id: parentId === null ? null : Number(parentId), slug: collectionSlug(name, crypto.randomUUID()), icon: 'i-lucide-folder',
                })
                return workspaceCollection(data.data)
            } finally { creatingCollection.value = false }
        },
        load(id: string) {
            const existing = requests.get(id)
            if (existing) return existing
            const request = axios.get<{ data: ResourceDetailData }>(route('groups.dashboard.resources.edit', { group: groupSlug(), resource: id }))
                .then(({ data }) => workspaceResource(data.data, activities())).finally(() => requests.delete(id))
            requests.set(id, request)
            return request
        },
    }
}
