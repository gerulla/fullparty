import type { WorkspaceDocument, WorkspaceResource } from '../Types/ResourceWorkspace'
import { workspaceCommandErrors } from './resourceWorkspace.ts'
import type { ResourceFieldErrors } from '../Types/ResourceValidation'

type Label = (key: string, params?: Record<string, string | number>) => string

export function resourceFieldPath(path: string): string {
    const key = path.replace(/^content\./, '')
    if (key.startsWith('body.')) return 'body'
    if (key.startsWith('tags.')) return 'tags'
    if (key.startsWith('activity_type_ids.')) return 'activity_type_ids'
    if (/^commands\.\d+\.embed\.(image|thumbnail)\./.test(key)) return key.split('.').slice(0, 4).join('.')
    return key
}

export function resourceFieldValue(draft: WorkspaceDocument | null, path: string): string {
    if (!draft) return ''
    const values = {
        ...draft, collection_id: draft.collectionId, access_level: draft.access,
        activity_type_ids: draft.activityTypeIds, character_id: draft.authorCharacterId,
        metadata_image_id: draft.cover,
        commands: draft.embeds.map(embed => ({ name: embed.command, embed })),
    }
    return JSON.stringify(path.split('.').reduce<any>((value, part) => value?.[part], values)) ?? ''
}

export function validateResourceFields(draft: WorkspaceDocument, resources: WorkspaceResource[], id: string, label: Label): ResourceFieldErrors {
    const errors: ResourceFieldErrors = {}
    const add = (path: string, key: string, params?: Record<string, string | number>) => {
        errors[path] ??= { message: label(key, params), value: resourceFieldValue(draft, path) }
    }
    const text = (path: string, value: string, max: number, required = false) => {
        if (required && !value.trim()) add(path, 'validation.required')
        if ([...value].length > max) add(path, 'validation.max_characters', { max })
    }
    const url = (path: string, value: string) => {
        if (!value) return
        try { if (!['https:', 'http:'].includes(new URL(value).protocol)) throw new Error() }
        catch { add(path, 'validation.url') }
        text(path, value, 2048)
    }
    if (!resources.some(resource => resource.id === id && resource.holsterId)) {
        text('title', draft.title, 200, true)
        text('description', draft.description, 1000)
    }
    if (draft.cover && !/^\/resource-assets\/[a-f0-9-]{36}$/i.test(draft.cover)) add('metadata_image_id', 'validation.image')
    if (draft.tags.length > 20) add('tags', 'validation.max_items', { max: 20 })
    draft.tags.forEach(tag => text('tags', tag, 50, true))
    if ((draft.activityTypeIds?.length ?? 0) > 30) add('activity_type_ids', 'validation.max_items', { max: 30 })
    if (!draft.body || draft.body.type !== 'doc') add('body', 'validation.document')
    if (draft.embeds.length > 15) add('commands', 'embed_limit')
    workspaceCommandErrors(draft, resources, id).forEach((error, index) => { if (error) add(`commands.${index}.name`, error) })
    draft.embeds.forEach((embed, index) => {
        const prefix = `commands.${index}.embed`
        text(`${prefix}.title`, embed.title, 256)
        text(`${prefix}.description`, embed.description, 4096)
        url(`${prefix}.url`, embed.url)
        if (!/^#[a-f0-9]{6}$/i.test(embed.color)) add(`${prefix}.color`, 'validation.color')
        if (embed.fields.length > 25) add(`${prefix}.fields`, 'validation.max_items', { max: 25 })
        embed.fields.forEach((field, fieldIndex) => {
            text(`${prefix}.fields.${fieldIndex}.name`, field.name, 256, true)
            text(`${prefix}.fields.${fieldIndex}.value`, field.value, 1024, true)
        })
        for (const key of ['image', 'thumbnail'] as const) {
            if (!/^\/resource-assets\/[a-f0-9-]{36}$/i.test(embed[key])) url(`${prefix}.${key}`, embed[key])
        }
        const count = [...draft.title + 'FullParty' + embed.title + embed.description + embed.fields.map(field => field.name + field.value).join('')].length
        if (count > 6000) add(prefix, 'validation.embed_total')
        if (!(embed.title.trim() || embed.description.trim() || embed.image || embed.thumbnail || embed.fields.length)) add(prefix, 'validation.embed_empty')
    })
    return errors
}
