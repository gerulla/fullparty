import type { JSONContent } from '@tiptap/core'
import type { RichTextDocument } from '../Types/RichText'

export function emptyRichTextDocument(): RichTextDocument {
    return { type: 'doc', content: [{ type: 'paragraph' }] }
}

export function richTextPlainText(node: JSONContent): string {
    if (node.type === 'text') return node.text ?? ''
    if (node.type === 'videoEmbed') return node.attrs?.title ?? ''
    return (node.content ?? []).map(richTextPlainText).join(['paragraph', 'heading'].includes(node.type ?? '') ? '' : '\n')
}

export function hasRichTextContent(node: JSONContent): boolean {
    return !!node.text?.trim() || ['image', 'inlineImage', 'horizontalRule', 'resourceLink', 'videoEmbed'].includes(node.type ?? '') || (node.content ?? []).some(hasRichTextContent)
}

export function safeEditorUrl(url: string, image = false): boolean {
    if (!url || url.length > 2048 || /[\s\\\u0000-\u001f\u007f]/.test(url)) return false
    if (/^\/(?!\/)/.test(url) || (!image && url.startsWith('#'))) return true
    try { return (image ? ['http:', 'https:'] : ['http:', 'https:', 'mailto:']).includes(new URL(url).protocol) }
    catch { return false }
}
