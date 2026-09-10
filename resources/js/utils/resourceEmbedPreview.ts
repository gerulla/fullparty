import type { WorkspaceEmbed } from '../Types/ResourceWorkspace'

export function resourceEmbedLink(value: string): string | undefined {
    try {
        const url = new URL(value.trim())
        return ['http:', 'https:'].includes(url.protocol) ? url.href : undefined
    } catch {
        return undefined
    }
}

export function resourceEmbedImage(value: string): string | undefined {
    const source = value.trim()
    if (/^\/(?![\/\\])/.test(source) || /^data:image\/(png|jpeg|webp|gif);base64,/i.test(source)) return source
    return resourceEmbedLink(source)
}

export function resourceEmbedFieldRows(fields: WorkspaceEmbed['fields'], hasThumbnail: boolean): WorkspaceEmbed['fields'][] {
    const rows: WorkspaceEmbed['fields'][] = []
    const columns = hasThumbnail ? 2 : 3
    let inlineRow: WorkspaceEmbed['fields'] = []
    const flush = () => {
        if (inlineRow.length) rows.push(inlineRow)
        inlineRow = []
    }
    for (const field of fields) {
        if (!field.inline) {
            flush()
            rows.push([field])
        } else {
            inlineRow.push(field)
            if (inlineRow.length === columns) flush()
        }
    }
    flush()
    return rows
}
