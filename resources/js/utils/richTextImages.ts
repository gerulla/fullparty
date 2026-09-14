import Image from '@tiptap/extension-image'
import { mergeAttributes, ResizableNodeView } from '@tiptap/core'
import { NodeSelection } from '@tiptap/pm/state'
import { Fragment } from '@tiptap/pm/model'
import type { RichTextImageAlign, RichTextImageLayout } from '../Types/RichText'

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        richTextImages: {
            setRichTextImageWidth: (width: number | null) => ReturnType
            setRichTextImageLayout: (layout: RichTextImageLayout) => ReturnType
            setRichTextImageAlign: (align: RichTextImageAlign) => ReturnType
        }
    }
}

const isImage = (name?: string) => name === 'image' || name === 'inlineImage'

const BlockImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            layout: {
                default: 'block',
                parseHTML: element => element.getAttribute('data-image-layout') ?? 'block',
                renderHTML: attrs => ({ 'data-image-layout': attrs.layout }),
            },
            align: {
                default: 'left',
                parseHTML: element => element.getAttribute('data-image-align') ?? 'left',
                renderHTML: attrs => ({ 'data-image-align': attrs.align }),
            },
        }
    },
    parseHTML: () => [{ tag: 'img[src]:not([data-inline-image]):not([src^="data:"])' }],
    addNodeView() {
        const create = this.parent?.()
        if (!create) return null
        return props => {
            const view = create(props)
            if (view instanceof ResizableNodeView) {
                view.maxSize = { width: 4096, height: 4096 }
            }
            const dom = view.dom as HTMLElement
            const image = dom.querySelector('img')
            const sync = (node: typeof props.node) => {
                dom.dataset.imageLayout = node.attrs.layout ?? 'block'
                dom.dataset.imageAlign = node.attrs.align ?? 'left'
                if (image) {
                    image.style.width = node.attrs.width ? `${node.attrs.width}px` : ''
                    image.style.height = 'auto'
                }
            }
            const update = view.update?.bind(view)
            view.update = (node, decorations, innerDecorations) => {
                if (!update?.(node, decorations, innerDecorations)) return false
                sync(node)
                return true
            }
            sync(props.node)
            return view
        }
    },
    addCommands() {
        return {
            ...this.parent?.(),
            setRichTextImageWidth: width => ({ state, tr, dispatch }) => {
                const { selection } = state
                if (!(selection instanceof NodeSelection) || !isImage(selection.node.type.name)) return false
                if (width !== null && (!Number.isInteger(width) || width < 16 || width > 4096)) return false
                if (dispatch) {
                    tr.setNodeMarkup(selection.from, undefined, { ...selection.node.attrs, width, height: null })
                    tr.setSelection(NodeSelection.create(tr.doc, selection.from))
                }
                return true
            },
            setRichTextImageAlign: align => ({ state, tr, dispatch }) => {
                const { selection } = state
                if (!(selection instanceof NodeSelection) || selection.node.type.name !== 'image' || !['left', 'center', 'right'].includes(align)) return false
                if (dispatch) {
                    tr.setNodeMarkup(selection.from, undefined, { ...selection.node.attrs, align })
                    tr.setSelection(NodeSelection.create(tr.doc, selection.from))
                }
                return true
            },
            setRichTextImageLayout: layout => ({ state, tr, dispatch }) => {
                const { selection, schema } = state
                if (!(selection instanceof NodeSelection) || !isImage(selection.node.type.name) || !['block', 'wrap-left', 'wrap-right', 'inline'].includes(layout)) return false
                const { node, from, to, $from } = selection
                const attrs = { ...node.attrs, layout: layout === 'inline' ? 'block' : layout }
                if ((node.type.name === 'inlineImage') === (layout === 'inline')) {
                    if (dispatch) {
                        tr.setNodeMarkup(from, undefined, attrs)
                        tr.setSelection(NodeSelection.create(tr.doc, from))
                    }
                    return true
                }
                if (!dispatch) return true
                if (layout === 'inline') {
                    const inline = schema.nodes.inlineImage.create(attrs)
                    const previous = $from.nodeBefore
                    const next = state.doc.resolve(to).nodeAfter
                    if (previous && ['paragraph', 'heading'].includes(previous.type.name)) {
                        tr.delete(from, to).insert(from - 1, inline)
                        tr.setSelection(NodeSelection.create(tr.doc, from - 1))
                    } else if (next && ['paragraph', 'heading'].includes(next.type.name)) {
                        tr.delete(from, to).insert(from + 1, inline)
                        tr.setSelection(NodeSelection.create(tr.doc, from + 1))
                    } else {
                        tr.replaceWith(from, to, schema.nodes.paragraph.create(null, inline))
                        tr.setSelection(NodeSelection.create(tr.doc, from + 1))
                    }
                } else {
                    const parent = $from.parent
                    const before = parent.content.cut(0, $from.parentOffset)
                    const after = parent.content.cut($from.parentOffset + node.nodeSize)
                    const needsLeadingParagraph = ['listItem', 'taskItem'].includes($from.node(-1).type.name) && $from.index(-1) === 0
                    const beforeNode = before.size || needsLeadingParagraph ? parent.copy(before) : null
                    const image = schema.nodes.image.create(attrs)
                    const replacement = Fragment.fromArray([...(beforeNode ? [beforeNode] : []), image, ...(after.size ? [parent.copy(after)] : [])])
                    const start = $from.before()
                    tr.replaceWith(start, $from.after(), replacement)
                    tr.setSelection(NodeSelection.create(tr.doc, start + (beforeNode?.nodeSize ?? 0)))
                }
                return true
            },
        }
    },
})

const InlineImage = Image.extend({
    name: 'inlineImage',
    addCommands: () => ({}),
    addInputRules: () => [],
    parseHTML: () => [{ tag: 'img[data-inline-image][src]:not([src^="data:"])', priority: 60 }],
    renderHTML: ({ HTMLAttributes }) => ['img', mergeAttributes(HTMLAttributes, { 'data-inline-image': '' })],
}).configure({ inline: true, resize: false })

export function richTextImageExtensions(resizable = false) {
    return [BlockImage.configure({ resize: resizable ? { enabled: true, alwaysPreserveAspectRatio: true, minWidth: 16, minHeight: 16, directions: ['bottom-left', 'bottom-right'] } : false }), InlineImage]
}
