import type { WorkspaceDocument, WorkspaceResource, WorkspaceRevisionSource } from '../Types/ResourceWorkspace'

export function resourceSavePayload(draft: WorkspaceDocument, original: WorkspaceResource, summary: string, publish: boolean, source?: WorkspaceRevisionSource | null) {
    const image = (value: string) => {
        const asset = value.match(/^\/resource-assets\/([a-f0-9-]{36})$/i)
        return asset ? { asset_id: asset[1] } : { url: value }
    }
    const coverId = draft.cover ? image(draft.cover).asset_id : null
    if (draft.cover && !coverId) throw new Error('invalid_resource_image')
    const previousAuthor = source?.document ?? original
    const authorChanged = draft.authorCharacterId !== previousAuthor.authorCharacterId || draft.author !== previousAuthor.author
    return {
        collection_id: draft.collectionId === null ? null : Number(draft.collectionId), summary: summary || undefined, publish,
        ...(source ? { source_revision_id: Number(source.id) } : {}),
        content: {
            title: draft.title, slug: original.slug, description: draft.description, body: draft.body,
            access_level: draft.access === 'admins' ? 'admin' : draft.access === 'moderators' ? 'moderator' : 'everyone',
            tags: draft.tags, activity_type_ids: draft.activityTypeIds ?? [], metadata_image_id: coverId,
            ...(authorChanged ? { character_id: draft.authorCharacterId ?? null } : {}),
            commands: draft.embeds.map(embed => ({
                name: embed.command, enabled: true,
                embed: {
                    ...(embed.title ? { title: embed.title } : {}),
                    ...(embed.description ? { description: embed.description } : {}),
                    color: /^#[a-f0-9]{6}$/i.test(embed.color) ? Number.parseInt(embed.color.slice(1), 16) : embed.color,
                    ...(embed.url ? { url: embed.url } : {}),
                    ...(embed.image ? { image: image(embed.image) } : {}),
                    ...(embed.thumbnail ? { thumbnail: image(embed.thumbnail) } : {}),
                    fields: embed.fields,
                },
            })),
        },
    }
}
