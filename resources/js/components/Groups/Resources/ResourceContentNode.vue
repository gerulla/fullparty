<script setup lang="ts">
import { computed, inject } from 'vue'
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'
import { useI18n } from 'vue-i18n'
import { resourceContentKey } from '@/Types/ResourceContent'
import ResourceReaderRow from './ResourceReaderRow.vue'
import ResourceVideoPlayer from './ResourceVideoPlayer.vue'

const props = defineProps(nodeViewProps)
const { t } = useI18n()
const context = inject(resourceContentKey, undefined)
const resource = computed(() => context?.resources.value.find(item => item.slug === props.node.attrs.resourceId))
</script>

<template>
    <NodeViewWrapper class="resource-content-node my-5 min-w-0" :class="{ 'ring-2 ring-primary': selected && editor.isEditable }" contenteditable="false">
        <div v-if="editor.isEditable" class="flex items-center justify-between border border-b-0 border-default bg-elevated px-2 py-1">
            <span data-drag-handle class="flex cursor-grab items-center gap-1.5 text-xs text-muted"><UIcon name="i-lucide-grip-vertical" class="size-4" />{{ t(`groups.resources.content.${node.type.name === 'resourceLink' ? 'resource_link' : 'video_embed'}`) }}</span>
            <UButton icon="i-lucide-trash-2" size="xs" color="neutral" variant="ghost" :aria-label="t('groups.resources.content.remove_block')" @click="deleteNode" />
        </div>
        <template v-if="node.type.name === 'resourceLink'">
            <ResourceReaderRow v-if="resource" :resource="resource" :href="context?.href(resource) ?? ''" :preview="editor.isEditable" class="border border-default bg-default px-4" />
            <div v-else class="flex items-center gap-3 border border-default bg-elevated p-4 text-sm text-muted"><UIcon name="i-lucide-file-question" class="size-5" />{{ t('groups.resources.content.resource_unavailable') }}</div>
        </template>
        <ResourceVideoPlayer v-else :url="node.attrs.url" :title="node.attrs.title" :preview="editor.isEditable" />
    </NodeViewWrapper>
</template>

<style scoped>
.resource-content-node :deep(.resource-reader-row) { color: inherit; text-decoration: none; font-weight: inherit; }
.resource-content-node :deep(.resource-reader-row img) { margin: 2px 0 0; width: 4rem; height: 3rem; object-fit: cover; }
</style>
