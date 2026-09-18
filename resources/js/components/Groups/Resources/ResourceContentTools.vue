<script setup lang="ts">
import { computed, inject, ref } from 'vue'
import type { Editor, JSONContent } from '@tiptap/core'
import { useI18n } from 'vue-i18n'
import { resourceContentKey } from '@/Types/ResourceContent'
import { parseResourceVideo } from '@/utils/resourceVideo'
import ResourceReaderRow from './ResourceReaderRow.vue'
import ResourceVideoPlayer from './ResourceVideoPlayer.vue'
import ResourceGearsetTools from './ResourceGearsetTools.vue'

const props = defineProps<{ editor: Editor; currentResourceId?: string }>()
const { t } = useI18n()
const l = (key: string) => t(`groups.resources.content.${key}`)
const context = inject(resourceContentKey)!
const resourceOpen = ref(false)
const videoOpen = ref(false)
const query = ref('')
const url = ref('')
const title = ref('')
const video = computed(() => parseResourceVideo(url.value.trim()))
const resources = computed(() => {
    const term = query.value.trim().toLocaleLowerCase()
    return context.resources.value.filter(item => item.slug !== props.currentResourceId && (!term || `${item.title} ${item.description} ${item.tags.join(' ')} ${item.slug}`.toLocaleLowerCase().includes(term) || term.includes(item.slug)))
})
let selection: ReturnType<Editor['state']['selection']['getBookmark']> | undefined
function open(kind: 'resource' | 'video') {
    selection = props.editor.state.selection.getBookmark()
    if (kind === 'resource') { query.value = ''; resourceOpen.value = true }
    else {
        const attrs = props.editor.getAttributes('videoEmbed')
        url.value = attrs.url ?? ''; title.value = attrs.title ?? ''; videoOpen.value = true
    }
}
function insert(node: JSONContent) {
    const editor = props.editor
    if (editor.isDestroyed || !editor.isEditable) return
    if (selection) {
        try { editor.view.dispatch(editor.state.tr.setSelection(selection.resolve(editor.state.doc))) } catch { /* Keep the current selection if the document changed. */ }
    }
    editor.chain().focus().insertContent(node).run()
    resourceOpen.value = false; videoOpen.value = false
}
</script>

<template>
    <ResourceGearsetTools :editor="editor" />
    <UButton icon="i-lucide-files" size="xs" color="neutral" variant="outline" :label="l('resource_link')" :disabled="!editor.isEditable" @click="open('resource')" />
    <UButton icon="i-lucide-video" size="xs" color="neutral" variant="outline" :label="l('video_embed')" :disabled="!editor.isEditable" @click="open('video')" />
    <UModal v-model:open="resourceOpen" :title="l('resource_link')" :description="l('resource_help')" :ui="{ content: 'max-w-2xl' }">
        <template #body>
            <UInput v-model="query" icon="i-lucide-search" autofocus :placeholder="l('search_resources')" :aria-label="l('search_resources')" class="w-full" />
            <div class="mt-4 max-h-96 overflow-auto">
                <button v-for="resource in resources" :key="resource.slug" type="button" class="block w-full cursor-pointer text-left focus-visible:outline-2 focus-visible:outline-primary" @click="insert({ type: 'resourceLink', attrs: { resourceId: resource.slug } })"><ResourceReaderRow :resource="resource" href="" preview /></button>
                <p v-if="!resources.length" class="py-6 text-center text-sm text-muted">{{ l('no_resources') }}</p>
            </div>
        </template>
    </UModal>
    <UModal v-model:open="videoOpen" :title="l('video_embed')" :description="l('video_help')" :ui="{ content: 'max-w-2xl' }">
        <template #body>
            <form class="space-y-4" @submit.prevent="video && insert({ type: 'videoEmbed', attrs: { url: video.url, title: title.trim() } })">
                <UFormField :label="l('video_url')" :error="url && !video ? l('invalid_video') : undefined"><UInput v-model="url" autofocus :maxlength="2048" placeholder="https://…" class="w-full" /></UFormField>
                <UFormField :label="l('video_title')"><UInput v-model="title" :maxlength="1000" class="w-full" /></UFormField>
                <ResourceVideoPlayer v-if="video" :url="video.url" :title="title" preview />
                <div class="flex justify-end"><UButton type="submit" :label="l('insert')" :disabled="!video" /></div>
            </form>
        </template>
    </UModal>
</template>
