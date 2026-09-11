import type { JSONContent } from '@tiptap/core'

export type RichTextDocument = JSONContent & { type: 'doc'; content: JSONContent[] }
export type RichTextImage = { src: string; alt?: string; title?: string }
