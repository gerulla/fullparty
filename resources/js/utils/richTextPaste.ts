import type { JSONContent } from '@tiptap/core'

function supportedStyle(key: string, value: unknown): boolean {
    if (typeof value !== 'string') return false
    switch (key) {
        case 'color':
        case 'backgroundColor':
            return /^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/i.test(value) || /^rgb\(\s*(?:\d{1,3}\s*,\s*){2}\d{1,3}\s*\)$/.test(value)
        case 'fontSize': return /^(?:[89]|[1-6][0-9]|7[0-2])px$/.test(value)
        case 'lineHeight': return ['1', '1.25', '1.5', '1.75', '2'].includes(value)
        case 'fontFamily': return ['Arial', 'Georgia', 'Verdana', 'monospace'].includes(value)
        default: return true
    }
}

export function normalizePastedRichText(node: JSONContent): JSONContent {
    return {
        ...node,
        ...(node.content ? { content: node.content.map(normalizePastedRichText) } : {}),
        ...(node.marks ? { marks: node.marks.flatMap(mark => {
            if (!['textStyle', 'highlight'].includes(mark.type)) return [mark]
            const attrs = Object.fromEntries(Object.entries(mark.attrs ?? {}).filter(([key, value]) => supportedStyle(key, value)))
            if (mark.type === 'textStyle' && !Object.keys(attrs).length) return []
            return [{ ...mark, attrs }]
        }) } : {}),
    }
}
