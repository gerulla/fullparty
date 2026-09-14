import { Extension } from '@tiptap/core'
import { TableKit } from '@tiptap/extension-table'
import { TextStyleKit } from '@tiptap/extension-text-style'
import TextAlign from '@tiptap/extension-text-align'
import { TaskList, TaskItem } from '@tiptap/extension-list'
import Highlight from '@tiptap/extension-highlight'
import Subscript from '@tiptap/extension-subscript'
import Superscript from '@tiptap/extension-superscript'
import { Plugin } from '@tiptap/pm/state'
import { Fragment, Slice, type Node as ProseMirrorNode } from '@tiptap/pm/model'
import { normalizePastedRichText } from './richTextPaste.ts'

export function richTextExtensions() {
    return [TableKit.configure({ table: { resizable: true } }), TextStyleKit,
        TextAlign.configure({ types: ['heading', 'paragraph'] }), TaskList, TaskItem.configure({ nested: true }),
        Highlight.configure({ multicolor: true }), Subscript, Superscript,
        Extension.create({
            name: 'supportedPasteStyles',
            addProseMirrorPlugins: () => [new Plugin({ props: {
                transformPasted: (slice, view) => {
                    const nodes: ProseMirrorNode[] = []
                    slice.content.forEach(node => nodes.push(view.state.schema.nodeFromJSON(normalizePastedRichText(node.toJSON()))))
                    return new Slice(Fragment.fromArray(nodes), slice.openStart, slice.openEnd)
                },
            } })],
        }),
        Extension.create({
            name: 'linkTitle',
            addGlobalAttributes: () => [{ types: ['link'], attributes: { title: { default: null, parseHTML: element => element.getAttribute('title') } } }],
        })]
}
