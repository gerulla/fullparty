import type { ResourceCollectionData, ResourceCommandData, ResourceDetailData, ResourceSnapshot, ResourceSummaryData } from '../Types/GroupResources'
import type { WorkspaceCollection, WorkspaceDocument, WorkspaceEmbed, WorkspaceResource } from '../Types/ResourceWorkspace'
import { emptyRichTextDocument } from './richText.ts'

export function workspaceCollection(data: ResourceCollectionData): WorkspaceCollection {
    return { id: String(data.id), name: data.name, parentId: data.parent_id === null ? null : String(data.parent_id), icon: data.icon || 'i-lucide-folder', order: data.sort_order ?? 0 }
}

export function workspaceEmbed(command?: ResourceCommandData): WorkspaceEmbed {
    const embed = command?.embed
    const imageUrl = (image?: { url?: string; asset_id?: string }) => image?.asset_id ? `/resource-assets/${image.asset_id}` : image?.url ?? ''
    return {
        enabled: command?.enabled ?? true, command: command?.name ?? '', title: embed?.title ?? '', description: embed?.description ?? '',
        color: `#${(embed?.color ?? 0x8457b0).toString(16).padStart(6, '0')}`, url: embed?.url ?? '',
        author: embed?.author?.name ?? '', authorUrl: embed?.author?.url ?? '', authorIcon: embed?.author?.icon_url ?? '',
        image: imageUrl(embed?.image), thumbnail: imageUrl(embed?.thumbnail), timestamp: command?.updated_at ?? embed?.timestamp ?? '', fields: embed?.fields ?? [],
    }
}

export function workspaceDocument(snapshot: ResourceSnapshot, collectionId: number | null, activities: Map<number, string>): WorkspaceDocument {
    return {
        title: snapshot.title, description: snapshot.description ?? '', body: snapshot.body ?? emptyRichTextDocument(), collectionId: collectionId === null ? null : String(collectionId),
        access: snapshot.access_level === 'admin' ? 'admins' : snapshot.access_level === 'moderator' ? 'moderators' : 'everyone',
        tags: snapshot.tags ?? [], activities: (snapshot.activity_type_ids ?? []).map(id => activities.get(id) ?? String(id)),
        activityTypeIds: snapshot.activity_type_ids ?? [], authorCharacterId: snapshot.author?.id ?? null,
        author: snapshot.author?.name ?? '', authorAvatar: snapshot.author?.avatar_url ?? undefined,
        cover: snapshot.metadata_image_id ? `/resource-assets/${snapshot.metadata_image_id}` : '',
        embeds: (snapshot.commands ?? []).map(workspaceEmbed),
    }
}

export function workspaceResource(data: ResourceSummaryData | ResourceDetailData, activities: Map<number, string>): WorkspaceResource {
    const detail = 'working_copy' in data ? data : null
    const snapshot = 'summary' in data ? { ...data.summary, commands: data.commands } : data.working_copy ?? data.published
    if (!snapshot) throw new Error('Resource has no readable content')
    return {
        ...workspaceDocument(snapshot, data.collection_id, activities), id: String(data.id),
        holsterId: data.holster_id, inheritedBodyHtml: detail?.inherited_body_html,
        version: data.version, uuid: data.uuid, isHome: data.is_home ?? false, slug: snapshot.slug ?? data.slug,
        status: data.status === 'archived' ? 'archived' : data.status === 'published' ? 'published' : 'draft',
        hasUnpublishedChanges: data.has_unpublished_changes,
        canPublish: data.can_publish ?? false,
        isPinned: data.is_pinned ?? false,
        readerUrls: data.reader_urls ?? null,
        order: data.sort_order, updatedAt: data.updated_at,
        published: detail?.published ? workspaceDocument(detail.published, data.collection_id, activities) : null,
        history: detail?.history.map(item => ({ id: String(item.id), kind: item.kind ?? 'edit', author: item.editor.name, authorAvatar: item.editor.avatar_url ?? undefined, summary: item.summary, at: item.created_at })) ?? [],
    }
}

export function untitledResourcePayload(collectionId: string | null, title: string, uniqueId: string) {
    return {
        collection_id: collectionId === null ? null : Number(collectionId),
        content: { title, slug: uniqueId, description: '', body: emptyRichTextDocument(), access_level: 'everyone', tags: [], activity_type_ids: [], commands: [] },
    }
}

export function collectionSlug(name: string, uniqueId: string): string {
    const base = name.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 100).replace(/-$/, '')
    return `${base || 'collection'}-${uniqueId}`
}

export function isValidCollectionName(name: string): boolean {
    return name.trim().length > 0 && !/[^A-Za-z0-9 .(){}\[\];_&-]/.test(name)
}
