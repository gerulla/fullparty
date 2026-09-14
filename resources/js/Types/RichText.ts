import type { JSONContent } from '@tiptap/core'

export type RichTextDocument = JSONContent & { type: 'doc'; content: JSONContent[] }
export type RichTextImage = { src: string; alt?: string; title?: string }
export type RichTextImageLayout = 'block' | 'wrap-left' | 'wrap-right' | 'inline'
export type RichTextImageAlign = 'left' | 'center' | 'right'
