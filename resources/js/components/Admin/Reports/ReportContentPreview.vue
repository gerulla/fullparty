<script setup lang="ts">
import type { ReportContentPreview } from '@/Types/Reports'
import '@/../css/rich-text.css'
defineProps<{ preview: ReportContentPreview; showTitle?: boolean }>()
</script>

<template>
    <div class="min-w-0 space-y-4">
        <h3 v-if="showTitle" class="font-semibold text-lg break-words">{{ preview.title }}</h3>
        <div v-if="preview.tags.length" class="flex flex-wrap gap-2"><UBadge v-for="tag in preview.tags" :key="tag" color="neutral" variant="subtle">{{ tag }}</UBadge></div>
        <p v-if="preview.description" class="text-muted whitespace-pre-wrap break-words">{{ preview.description }}</p>
        <!-- HTML is rendered by ReportContentPreview through the server's validated RichTextDocument allowlist. -->
        <div v-if="preview.html" class="rich-text-content min-w-0 overflow-x-auto break-words" v-html="preview.html" />
        <p v-else-if="preview.text" class="whitespace-pre-wrap break-words">{{ preview.text }}</p>
        <dl v-if="preview.fields.length" class="divide-y divide-default border border-default">
            <div v-for="(field, index) in preview.fields" :key="index" class="p-3 space-y-1">
                <dt class="text-xs font-semibold text-muted">{{ field.label }}</dt><dd class="text-sm whitespace-pre-wrap break-words">{{ field.value }}</dd>
            </div>
        </dl>
    </div>
</template>
