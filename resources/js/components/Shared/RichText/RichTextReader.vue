<script setup lang="ts">
import type { RichTextDocument } from '@/Types/RichText'
import { richTextExtensions } from '@/utils/richTextExtensions'
import '@/../css/rich-text.css'

defineProps<{ document: RichTextDocument }>()
const extensions = richTextExtensions()
</script>

<template>
    <UEditor :key="JSON.stringify(document)" :model-value="document" content-type="json" :editable="false" :mention="false" :starter-kit="{ link: { openOnClick: true } }" :extensions="extensions" :ui="{ base: 'rich-text-content focus:outline-none' }" />
</template>
