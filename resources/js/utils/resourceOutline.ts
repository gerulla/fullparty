import type { JSONContent } from '@tiptap/core'
import type { RichTextDocument } from '../Types/RichText'
import type { ResourceReaderSection } from '../Types/GroupResources'

export function resourceOutline(document: RichTextDocument): { document: RichTextDocument; sections: ResourceReaderSection[] } {
    const headings: { id: string; title: string; level: number }[] = []
    const used = new Set<string>()
    const plainText = (node: JSONContent): string => node.type === 'hardBreak' ? ' ' : node.text ?? (node.content ?? []).map(plainText).join('')
    function visit(node: JSONContent): JSONContent {
        const copy = { ...node, ...(node.attrs ? { attrs: { ...node.attrs } } : {}), ...(node.content ? { content: node.content.map(visit) } : {}) }
        if (node.type !== 'heading') return copy
        const title = plainText(node).trim()
        if (!title) return copy
        const slug = title.normalize('NFKC').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '').slice(0, 80) || 'section'
        const base = `resource-section-${slug}`
        let id = base
        for (let suffix = 2; used.has(id); suffix++) id = `${base}-${suffix}`
        used.add(id)
        copy.attrs = { ...copy.attrs, readerAnchor: id }
        headings.push({ id, title, level: Math.min(6, Math.max(1, Number(node.attrs?.level) || 2)) })
        return copy
    }
    const result = visit(document) as RichTextDocument
    const baseLevel = Math.min(...headings.map(item => item.level))
    return { document: result, sections: headings.map(({ id, title, level }) => ({ id, title, depth: Math.min(3, level - baseLevel) })) }
}
