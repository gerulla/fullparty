<script setup lang="ts">
import '@/bootstrap/markdownEditor.js'
import { MdPreview } from 'md-editor-v3'

defineProps<{ content: string }>()
</script>

<template>
    <MdPreview :model-value="content" theme="dark" language="en-US" :no-mermaid="true" :no-katex="true" :no-highlight="true" class="discord-markdown" />
</template>

<style scoped>
.discord-markdown { --md-bk-color: transparent; --md-color: inherit; background: transparent; color: inherit; font: inherit; min-width: 0; }
.discord-markdown :deep(.md-editor-preview-wrapper) { padding: 0; }
.discord-markdown :deep(.md-editor-preview) { color: inherit; font: inherit; word-break: normal; overflow-wrap: anywhere; }
.discord-markdown :deep(p) { margin: 0; }
.discord-markdown :deep(p + p) { margin-top: 8px; }
.discord-markdown :deep(strong) { font-weight: 700; }
.discord-markdown :deep(a) { color: #00a8fc; text-decoration: none; }
.discord-markdown :deep(a:hover) { text-decoration: underline; }
.discord-markdown :deep(code) { background: #1e1f22; border-radius: 3px; padding: 1px 3px; font-family: var(--font-mono); font-size: 12px; }
.discord-markdown :deep(pre) { margin: 6px 0 0; padding: 8px; border: 1px solid #1e1f22; border-radius: 4px; background: #2b2d31; white-space: pre-wrap; }
.discord-markdown :deep(pre code) { padding: 0; background: transparent; }
.discord-markdown :deep(.md-editor-code-head) { display: none; }
.discord-markdown :deep(blockquote) { margin: 4px 0; padding: 0 0 0 12px; border-left: 4px solid #4e5058; background: transparent; color: inherit; }
.discord-markdown :deep(ul), .discord-markdown :deep(ol) { margin: 0; padding-left: 20px; }
.discord-markdown :deep(li) { margin: 0; }
.discord-markdown :deep(h1), .discord-markdown :deep(h2), .discord-markdown :deep(h3) { margin: 8px 0 4px; padding: 0; border: 0; color: #f2f3f5; font-weight: 700; }
.discord-markdown :deep(h1) { font-size: 24px; }
.discord-markdown :deep(h2) { font-size: 20px; }
.discord-markdown :deep(h3) { font-size: 16px; }
</style>
