<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'
import { useI18n } from 'vue-i18n'
import ResourceGearsetEmbed from './ResourceGearsetEmbed.vue'

defineProps(nodeViewProps)
const { t } = useI18n()
</script>

<template>
    <NodeViewWrapper contenteditable="false" class="my-4 clear-both" :class="{ 'outline-2 outline-primary': selected && editor.isEditable }">
        <div v-if="editor.isEditable" class="flex flex-wrap items-center gap-2 border border-b-0 border-default bg-elevated p-2">
            <UIcon name="i-lucide-grip-vertical" data-drag-handle class="cursor-grab text-muted" />
            <span class="mr-auto text-xs text-muted">XIVGear</span>
            <UButton v-for="mode in (['expanded', 'compact'] as const)" :key="mode" size="xs" :color="node.attrs.display === mode ? 'primary' : 'neutral'" :variant="node.attrs.display === mode ? 'soft' : 'ghost'" :label="t(`xivgear.${mode}`)" :aria-pressed="node.attrs.display === mode" @click="updateAttributes({ display: mode })" />
            <UButton icon="i-lucide-trash-2" size="xs" color="error" variant="ghost" :aria-label="t('xivgear.remove')" @click="deleteNode" />
        </div>
        <ResourceGearsetEmbed :snapshots="node.attrs.snapshots" :display="node.attrs.display" class="!my-0" />
    </NodeViewWrapper>
</template>
