import type { JSONContent } from '@tiptap/core'
import type { RichTextDocument } from '../Types/RichText'
import type { GearsetSnapshot } from '../Types/XivGear'

export function emptyRichTextDocument(): RichTextDocument {
    return { type: 'doc', content: [{ type: 'paragraph' }] }
}

export function richTextPlainText(node: JSONContent): string {
    if (node.type === 'text') return node.text ?? ''
    if (node.type === 'gameIcon') return `:${node.attrs?.shortcode ?? ''}:`
    if (node.type === 'videoEmbed') return node.attrs?.title ?? ''
    if (node.type === 'xivGear') return ((node.attrs?.snapshots ?? []) as GearsetSnapshot[]).map(set => [set.name, set.description, set.items.map(item => Object.values(item.names).join(' ')).join(' ')].join('\n')).join('\n')
    return (node.content ?? []).map(richTextPlainText).join(['paragraph', 'heading'].includes(node.type ?? '') ? '' : '\n')
}

export function hasRichTextContent(node: JSONContent): boolean {
    return !!node.text?.trim() || ['image', 'inlineImage', 'gameIcon', 'horizontalRule', 'resourceLink', 'videoEmbed', 'xivGear'].includes(node.type ?? '') || (node.content ?? []).some(hasRichTextContent)
}

export function safeEditorUrl(url: string, image = false): boolean {
    if (!url || url.length > 2048 || /[\s\\\u0000-\u001f\u007f]/.test(url)) return false
    if (/^\/(?!\/)/.test(url) || (!image && url.startsWith('#'))) return true
    try { return (image ? ['http:', 'https:'] : ['http:', 'https:', 'mailto:']).includes(new URL(url).protocol) }
    catch { return false }
}
