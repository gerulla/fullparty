import { Node, mergeAttributes } from '@tiptap/core'
import { NodeSelection, Plugin, PluginKey, TextSelection, type EditorState } from '@tiptap/pm/state'
import type { Node as ProseMirrorNode } from '@tiptap/pm/model'
import type { RichTextImage, RichTextImageAlign } from '../Types/RichText'

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        richTextImageGroups: {
            createImageGroup: () => ReturnType
            setImageGroupAlign: (align: RichTextImageAlign) => ReturnType
            addImageToGroup: (image: RichTextImage) => ReturnType
            ungroupImages: () => ReturnType
            deleteRichTextImage: () => ReturnType
        }
    }
}

export function selectedImageGroup(state: EditorState) {
    const { selection } = state
    if (selection instanceof NodeSelection && selection.node.type.name === 'imageGroup') return { node: selection.node, pos: selection.from }
    for (let depth = selection.$from.depth; depth > 0; depth--) {
        const node = selection.$from.node(depth)
        if (node.type.name === 'imageGroup') return { node, pos: selection.$from.before(depth) }
    }
    return null
}

export const ImageGroup = Node.create({
    name: 'imageGroup',
    group: 'block',
    // Empty groups are removed below; image+ would auto-create an image with no source when cutting the last image.
    content: 'image*',
    defining: true,
    isolating: true,
    addAttributes: () => ({ align: {
        default: 'center',
        parseHTML: element => element.getAttribute('data-image-group-align') ?? 'center',
        renderHTML: attrs => ({ 'data-image-group-align': attrs.align }),
    } }),
    parseHTML: () => [{ tag: 'div[data-image-group]' }],
    renderHTML: ({ HTMLAttributes }) => ['div', mergeAttributes(HTMLAttributes, { 'data-image-group': '' }), 0],
    addProseMirrorPlugins() {
        return [new Plugin({
            key: new PluginKey('removeEmptyImageGroups'),
            appendTransaction: (transactions, _oldState, state) => {
                if (!transactions.some(transaction => transaction.docChanged)) return null
                const empty: { from: number; to: number }[] = []
                state.doc.descendants((node, pos) => {
                    if (node.type.name === 'imageGroup' && !node.childCount) empty.push({ from: pos, to: pos + node.nodeSize })
                })
                if (!empty.length) return null
                const tr = state.tr
                for (const { from, to } of empty.reverse()) tr.delete(from, to)
                return tr
            },
        })]
    },
    addCommands() {
        return {
            createImageGroup: () => ({ state, tr, dispatch }) => {
                const { selection, schema } = state
                if (!(selection instanceof NodeSelection) || selection.node.type.name !== 'image' || selectedImageGroup(state)) return false
                const parent = selection.$from.parent
                let start = selection.$from.index()
                let end = start + 1
                let from = selection.from
                let to = selection.to
                while (start > 0 && parent.child(start - 1).type.name === 'image') from -= parent.child(--start).nodeSize
                while (end < parent.childCount && parent.child(end).type.name === 'image') to += parent.child(end++).nodeSize
                if (!parent.canReplaceWith(start, end, schema.nodes.imageGroup)) return false
                if (dispatch) {
                    const images = Array.from({ length: end - start }, (_, index) => {
                        const image = parent.child(start + index)
                        return schema.nodes.image.create({ ...image.attrs, layout: 'block', align: 'left' })
                    })
                    tr.replaceWith(from, to, schema.nodes.imageGroup.create({ align: 'center' }, images))
                    tr.setSelection(NodeSelection.create(tr.doc, from + 1))
                }
                return true
            },
            setImageGroupAlign: align => ({ state, tr, dispatch }) => {
                const group = selectedImageGroup(state)
                if (!group || !['left', 'center', 'right'].includes(align)) return false
                if (dispatch) tr.setNodeMarkup(group.pos, undefined, { ...group.node.attrs, align })
                return true
            },
            addImageToGroup: image => ({ state, tr, dispatch }) => {
                const group = selectedImageGroup(state)
                if (!group) return false
                if (dispatch) {
                    const pos = group.pos + group.node.nodeSize - 1
                    const width = group.node.lastChild?.attrs.width ?? 240
                    tr.insert(pos, state.schema.nodes.image.create({ ...image, width, layout: 'block', align: 'left' }))
                    tr.setSelection(NodeSelection.create(tr.doc, pos))
                }
                return true
            },
            ungroupImages: () => ({ state, tr, dispatch }) => {
                const group = selectedImageGroup(state)
                if (!group) return false
                if (dispatch) {
                    const images: ProseMirrorNode[] = []
                    group.node.forEach(image => images.push(state.schema.nodes.image.create({ ...image.attrs, layout: 'block', align: group.node.attrs.align })))
                    tr.replaceWith(group.pos, group.pos + group.node.nodeSize, images)
                    tr.setSelection(NodeSelection.create(tr.doc, group.pos))
                }
                return true
            },
            deleteRichTextImage: () => ({ state, commands }) => {
                if (!(state.selection instanceof NodeSelection) || !['image', 'inlineImage', 'imageGroup'].includes(state.selection.node.type.name)) return false
                const group = selectedImageGroup(state)
                return group?.node.childCount === 1 ? commands.deleteNode('imageGroup') : commands.deleteSelection()
            },
        }
    },
    addKeyboardShortcuts() {
        const deleteLastImage = () => selectedImageGroup(this.editor.state)?.node.childCount === 1 && this.editor.commands.deleteRichTextImage()
        return {
            Backspace: deleteLastImage,
            Delete: deleteLastImage,
            Enter: () => {
                const group = selectedImageGroup(this.editor.state)
                if (!group) return false
                const pos = group.pos + group.node.nodeSize
                const tr = this.editor.state.tr.insert(pos, this.editor.schema.nodes.paragraph.create())
                tr.setSelection(TextSelection.create(tr.doc, pos + 1))
                this.editor.view.dispatch(tr)
                return true
            },
        }
    },
})
