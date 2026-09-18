<script setup lang="ts">
import { inject, onBeforeUnmount } from 'vue'
import type { Editor } from '@tiptap/core'
import { useI18n } from 'vue-i18n'
import { gearsetImportKey } from '@/Types/XivGear'
import { useResourceGearsetImport } from '@/composables/useResourceGearsetImport'
import ResourceGearsetModal from './ResourceGearsetModal.vue'

const props = defineProps<{ editor: Editor }>()
const context = inject(gearsetImportKey)
const { t } = useI18n()
const importer = useResourceGearsetImport(() => context!.groupSlug(), () => t('xivgear.import_failed'))
const { state } = importer
let selection: ReturnType<Editor['state']['selection']['getBookmark']> | undefined
function open() {
    selection = props.editor.state.selection.getBookmark()
    const attrs = props.editor.getAttributes('xivGear')
    importer.open(attrs.snapshots, attrs.display)
}
function insert() {
    const editor = props.editor
    if (!state.snapshots.length || editor.isDestroyed || !editor.isEditable) return
    if (selection) {
        try { editor.view.dispatch(editor.state.tr.setSelection(selection.resolve(editor.state.doc))) } catch { /* Keep the current selection if the document changed. */ }
    }
    editor.chain().focus().insertContent({ type: 'xivGear', attrs: { display: state.display, snapshots: JSON.parse(JSON.stringify(state.snapshots)) } }).run()
    importer.close()
}
onBeforeUnmount(importer.close)
</script>

<template>
    <UButton v-if="context" icon="i-lucide-swords" size="xs" color="neutral" variant="outline" :label="t('xivgear.toolbar')" :disabled="!editor.isEditable" @click="open" />
    <ResourceGearsetModal v-bind="state" @close="importer.close" @load="importer.load" @insert="insert" @update:url="state.url = $event" @update:display="state.display = $event" @update:selected="state.selected = $event; state.snapshots = []" />
</template>
