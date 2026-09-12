<script setup lang="ts">
import { computed, provide, ref } from 'vue'
import { resourceContentKey } from '@/Types/ResourceContent'
import type { ResourceReaderSummary } from '@/Types/GroupResources'
import { resourceContentExtensions } from './resourceContentExtensions'
import ResourceContentTools from './ResourceContentTools.vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController } from '@/Types/ResourceWorkspace'
import type { RichTextImage } from '@/Types/RichText'
import type { ResourceImageSelection } from '@/Types/ResourceImages'
import { useResourceImageUpload } from '@/composables/useResourceImageUpload'
import RichTextEditor from '@/components/Shared/RichText/RichTextEditor.vue'
import ResourceImageLibraryModal from './ResourceImageLibraryModal.vue'

const props = defineProps<{ workspace: ResourceWorkspaceController }>()
const { t } = useI18n()
const l = (key: string) => t('groups.resources.workspace.' + key)
const draft = computed(() => props.workspace.state.draft)
const contentExtensions = resourceContentExtensions()
const linkedResources = computed<ResourceReaderSummary[]>(() => props.workspace.state.resources.filter(item => item.uuid && item.status !== 'archived').map(item => ({
    id: Number(item.id), slug: item.uuid!, collection_id: item.collectionId ? Number(item.collectionId) : null, is_home: Boolean(item.isHome),
    title: item.title, description: item.description, tags: item.tags, activity_type_ids: item.activityTypeIds ?? [],
    metadata_image_id: /^\/resource-assets\//.test(item.cover) ? item.cover.split('/').at(-1)! : null,
    author: { name: item.author }, access_level: item.access === 'admins' ? 'admin' : item.access === 'moderators' ? 'moderator' : 'everyone', published_at: null,
})))
provide(resourceContentKey, { resources: linkedResources, href: () => '' })
const upload = useResourceImageUpload()
const imageLibraryOpen = ref(false)
let insert: ((image: RichTextImage) => void) | undefined
function openImages(callback: (image: RichTextImage) => void) { insert = callback; imageLibraryOpen.value = true }
function insertImage(image: ResourceImageSelection) { insert?.({ src: image.url, alt: image.alt_text || image.name, title: image.caption || undefined }) }
const access = computed(() => props.workspace.accessLevels.map(value => ({ value, label: l(value) })))
const activityIds = computed({
    get: () => draft.value?.activityTypeIds ?? [],
    set: (ids: number[]) => {
        if (!draft.value) return
        draft.value.activityTypeIds = ids
        draft.value.activities = ids.map(id => props.workspace.activityOptions.find(item => item.value === id)?.label ?? String(id))
    },
})
const activityOptions = computed(() => [...props.workspace.activityOptions, ...activityIds.value.filter(id => !props.workspace.activityOptions.some(item => item.value === id)).map(value => ({ value, label: String(value) }))])
</script>

<template>
    <section v-if="draft" class="studio-document">
        <div class="studio-metadata">
            <UFormField name="access_level" data-resource-field="access_level" :error="workspace.fieldError('access_level')"><USelect v-model="draft.access" :items="access" :disabled="workspace.selected?.isHome" icon="i-lucide-users" size="sm" :aria-label="l('access')" class="studio-access" /></UFormField>
            <UFormField name="activity_type_ids" data-resource-field="activity_type_ids" :error="workspace.fieldError('activity_type_ids')" class="w-96 max-w-full min-w-0 shrink-0"><USelectMenu v-model="activityIds" multiple :items="activityOptions" value-key="value" icon="i-lucide-gamepad-2" size="sm" :aria-label="l('activities')" :placeholder="l('activities')" class="w-full" :ui="{ content: 'rounded-none max-w-[calc(100vw-2rem)]', item: 'rounded-none', itemLabel: 'whitespace-normal wrap-anywhere' }">
                <template #default><span class="studio-activity-values"><span v-for="activity in draft.activities" :key="activity">{{ activity }}</span><span v-if="!draft.activities.length">{{ l('activities') }}</span></span></template>
            </USelectMenu></UFormField>
            <UFormField name="tags" data-resource-field="tags" :error="workspace.fieldError('tags')" class="studio-tags"><UInputTags v-model="draft.tags" icon="i-lucide-tag" size="sm" :aria-label="l('tags')" :placeholder="l('add_tag')" :add-on-blur="true" class="w-full" :ui="{ base: 'rounded-none bg-transparent', item: 'rounded-none', input: 'min-w-12' }" /></UFormField>
        </div>
        <UFormField name="title" data-resource-field="title" :error="workspace.fieldError('title')"><UInput v-model="draft.title" :placeholder="l('title')" :aria-label="l('title')" class="studio-title w-full" :ui="{ base: 'rounded-none px-3 py-2 text-3xl font-semibold bg-transparent' }" /></UFormField>
        <UFormField name="description" data-resource-field="description" :error="workspace.fieldError('description')"><UTextarea v-model="draft.description" :placeholder="l('description')" :aria-label="l('description')" :rows="1" autoresize class="studio-description w-full" :ui="{ base: 'rounded-none px-3 py-2 text-sm bg-transparent resize-none' }" /></UFormField>
        <div class="studio-editor-surface" data-resource-field="body" :class="{ 'ring-2 ring-error': workspace.fieldError('body') }">
            <RichTextEditor v-model="draft.body" image-library :upload="upload" :additional-extensions="contentExtensions" class="flex-1 min-w-0" @image="openImages" @save="workspace.save()">
                <template #tools="{ editor }"><ResourceContentTools :editor="editor" :current-resource-id="workspace.selected?.uuid" /></template>
            </RichTextEditor>
        </div>
        <p v-if="workspace.fieldError('body')" role="alert" class="text-sm text-error">{{ workspace.fieldError('body') }}</p>
        <ResourceImageLibraryModal v-model:open="imageLibraryOpen" @select="insertImage" />
    </section>
</template>

<style scoped>
.studio-document { display: flex; flex-direction: column; gap: 12px; min-width: 0; min-height: 0; padding: 12px 14px 14px; }
.studio-metadata { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; flex: none; min-width: 0; }
.studio-access { width: 134px; }
.studio-activity-values { display: flex; align-items: center; flex-wrap: wrap; gap: 4px; min-width: 0; }
.studio-activity-values > span { max-width: 100%; padding: 1px 5px; background: var(--ui-bg-elevated); font-size: 12px; white-space: normal; overflow-wrap: anywhere; }
.studio-tags { flex: 1; min-width: 180px; }
.studio-title, .studio-description { flex: none; }
.studio-title :deep(input) { font-size: 32px; font-weight: 600; line-height: 40px; padding: 4px 12px; }
.studio-description :deep(textarea) { font-size: 14px; font-weight: 400; }
.studio-editor-surface { flex: 1; min-height: 240px; display: flex; border: 1px solid var(--ui-border); background: color-mix(in srgb, var(--ui-bg-elevated) 50%, transparent); }
@media (max-width: 767px) { .studio-editor-surface { flex: none; height: 620px; } }
</style>
