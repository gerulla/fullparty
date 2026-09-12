<script setup lang="ts">
import type { RichTextDocument } from '@/Types/RichText'
import { Extension, type Extensions } from '@tiptap/core'
import { richTextExtensions } from '@/utils/richTextExtensions'
import '@/../css/rich-text.css'

const props = defineProps<{ document: RichTextDocument; additionalExtensions?: Extensions }>()
const extensions = [...richTextExtensions(), ...(props.additionalExtensions ?? [])]
extensions.push(Extension.create({
    name: 'readerHeadingAnchors',
    addGlobalAttributes: () => [{ types: ['heading'], attributes: {
        readerAnchor: { default: null, renderHTML: attributes => typeof attributes.readerAnchor === 'string' && attributes.readerAnchor.startsWith('resource-section-') ? { id: attributes.readerAnchor, tabindex: '-1' } : {} },
    } }],
}))
</script>

<template>
    <UEditor :key="JSON.stringify(document)" :model-value="document" content-type="json" :editable="false" :mention="false" :starter-kit="{ link: { openOnClick: true } }" :extensions="extensions" :ui="{ base: 'rich-text-content focus:outline-none' }" />
</template>
