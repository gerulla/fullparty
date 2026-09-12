<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Editor, Extensions } from '@tiptap/core'
import type { RichTextDocument, RichTextImage } from '@/Types/RichText'
import { richTextExtensions } from '@/utils/richTextExtensions'
import { emptyRichTextDocument, richTextPlainText, safeEditorUrl } from '@/utils/richText'
import RichTextToolbar from './RichTextToolbar.vue'
import '@/../css/rich-text.css'

const props = withDefaults(defineProps<{ modelValue: RichTextDocument; maxLength?: number; imageLibrary?: boolean; upload?: (file: File) => Promise<string>; additionalExtensions?: Extensions }>(), { maxLength: 200000 })
const emit = defineEmits<{ 'update:modelValue': [value: RichTextDocument]; save: []; image: [insert: (image: RichTextImage) => void] }>()
const { t } = useI18n()
const instance = ref<{ editor: Editor }>()
const extensions = [...richTextExtensions(), ...(props.additionalExtensions ?? [])]
const error = ref('')
const imageOpen = ref(false)
const imageUrl = ref('')
const imageAlt = ref('')
let imageInsert: ((image: RichTextImage) => void) | undefined
const length = computed(() => richTextPlainText(props.modelValue).length)
const model = computed({ get: () => props.modelValue ?? emptyRichTextDocument(), set: (value: RichTextDocument) => emit('update:modelValue', value) })
function insertion(editor: Editor) {
    let selection = editor.state.selection.getBookmark()
    return (image: RichTextImage) => {
        if (editor.isDestroyed || !safeEditorUrl(image.src, true)) return
        try { editor.view.dispatch(editor.state.tr.setSelection(selection.resolve(editor.state.doc))) } catch { /* The document may have changed during an upload. */ }
        editor.chain().focus().setImage(image).run()
        selection = editor.state.selection.getBookmark()
    }
}
function openImage(editor: Editor) {
    imageInsert = insertion(editor)
    if (props.imageLibrary) emit('image', imageInsert)
    else { imageUrl.value = ''; imageAlt.value = ''; imageOpen.value = true }
}
async function uploadFiles(files: File[]) {
    const editor = instance.value?.editor
    if (!props.upload || !editor) { error.value = t('rich_text.upload_failed'); return }
    error.value = ''
    const insert = insertion(editor)
    editor.setEditable(false)
    try {
        for (const file of files) {
            const url = await props.upload(file)
            insert({ src: url, alt: file.name })
        }
    } catch { error.value = t('rich_text.upload_failed') }
    finally { if (!editor.isDestroyed) editor.setEditable(true) }
}
const editorProps = {
    handleKeyDown: (_view: unknown, event: KeyboardEvent) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); emit('save'); return true }
        return false
    },
    handlePaste: (_view: unknown, event: ClipboardEvent) => {
        const files = [...(event.clipboardData?.files ?? [])]
        if (!files.length) return false
        event.preventDefault(); void uploadFiles(files); return true
    },
    handleDrop: (_view: unknown, event: DragEvent) => {
        const files = [...(event.dataTransfer?.files ?? [])]
        if (!files.length) return false
        event.preventDefault(); void uploadFiles(files); return true
    },
}
</script>

<template>
    <div class="rich-text-editor flex min-h-0 min-w-0 flex-col">
        <UAlert v-if="error || length > maxLength" :title="error || t('rich_text.too_long', { max: maxLength })" color="error" variant="soft" />
        <UEditor ref="instance" v-model="model" content-type="json" :extensions="extensions" :mention="false" :image="{ resize: { enabled: true, alwaysPreserveAspectRatio: true } }" :editor-props="editorProps" :ui="{ root: 'flex min-h-0 flex-1 flex-col', content: 'min-h-0 flex-1 overflow-auto', base: 'rich-text-content min-h-full p-4 focus:outline-none' }">
            <template #default="{ editor }"><RichTextToolbar :editor="editor" @image="openImage(editor)"><slot name="tools" :editor="editor" /></RichTextToolbar></template>
        </UEditor>
        <div class="border-t border-default px-3 py-1 text-xs text-muted" aria-live="polite">{{ t('rich_text.character_count', { count: length, max: maxLength }) }}</div>
        <UModal v-model:open="imageOpen" :title="t('rich_text.image')">
            <template #body><form class="space-y-4" @submit.prevent="imageInsert?.({ src: imageUrl, alt: imageAlt }); imageOpen = false"><UFormField :label="t('rich_text.url')"><UInput v-model="imageUrl" autofocus class="w-full" /></UFormField><UFormField :label="t('rich_text.alt_text')"><UInput v-model="imageAlt" :maxlength="1000" class="w-full" /></UFormField><div class="flex justify-end"><UButton type="submit" :label="t('rich_text.insert')" :disabled="!safeEditorUrl(imageUrl, true)" /></div></form></template>
        </UModal>
    </div>
</template>
