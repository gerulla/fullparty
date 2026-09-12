<script setup lang="ts">
import { workspaceHasUnpublishedChanges } from '@/utils/resourceWorkspace'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController } from '@/Types/ResourceWorkspace'
import ResourceEmbedList from './ResourceEmbedList.vue'
import ResourceEmbedPreview from './ResourceEmbedPreview.vue'
import ResourceImagePicker from './ResourceImagePicker.vue'

const props = defineProps<{ workspace: ResourceWorkspaceController }>()
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const resource = computed(() => props.workspace.selected)
const draft = computed(() => props.workspace.state.draft)
const activeTab = computed({
    get: () => props.workspace.state.inspectorTab,
    set: value => { props.workspace.state.inspectorTab = value; if (value === 'resource') props.workspace.showResourceEditor() },
})
const authors = computed(() => {
    const options = props.workspace.authors.map(item => ({ value: item.id ? `character:${item.id}` : 'account', label: item.name, author: item }))
    if (draft.value && !options.some(item => item.author.name === draft.value?.author && (item.author.id ?? null) === (draft.value.authorCharacterId ?? null))) {
        options.unshift({ value: 'original', label: draft.value.author, author: { id: draft.value.authorCharacterId, name: draft.value.author, avatar_url: draft.value.authorAvatar } })
    }
    return options
})
const author = computed({
    get: () => authors.value.find(item => item.author.name === draft.value?.author && (item.author.id ?? null) === (draft.value?.authorCharacterId ?? null))?.value ?? 'original',
    set: value => {
        const selected = authors.value.find(item => item.value === value)?.author
        if (!draft.value || !selected) return
        draft.value.author = selected.name; draft.value.authorCharacterId = selected.id ?? null; draft.value.authorAvatar = selected.avatar_url ?? undefined
    },
})
const authorAvatar = computed(() => draft.value?.authorAvatar)
const tabs = computed(() => [
    { label: l('resource'), value: 'resource' },
    { label: l('discord'), value: 'discord' },
    { label: l('history'), value: 'history' },
])
const folders = computed(() => [{ value: 'root', label: l('root'), icon: 'i-lucide-folder' }, ...props.workspace.state.collections.map(item => ({ value: item.id, label: item.name, icon: item.icon }))])
const folder = computed({ get: () => draft.value?.collectionId ?? 'root', set: value => { if (draft.value) draft.value.collectionId = value === 'root' ? null : value } })
const selectedFolder = computed(() => folders.value.find(item => item.value === folder.value))
function date(value: string) { return new Date(value).toLocaleString(locale.value, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) }
const editors = computed(() => [...new Map(resource.value?.history.map(item => [item.author, item]) ?? []).values()])
const historyItems = computed(() => resource.value?.history.map(item => ({ id: item.id, kind: item.kind, date: date(item.at), title: item.summary, description: item.author, avatar: { src: item.authorAvatar, alt: item.author } })) ?? [])
</script>

<template>
    <aside class="studio-inspector">
        <template v-if="workspace.state.mode === 'editor' && draft">
            <UTabs v-model="activeTab" :items="tabs" :content="false" variant="link" size="sm" class="studio-inspector-tabs" :ui="{ list: 'w-full justify-start rounded-none px-3', trigger: 'flex-none rounded-none px-4 py-3', indicator: 'rounded-none' }" />
            <div class="studio-inspector-content">
                <div v-if="workspace.state.inspectorTab === 'resource'" class="space-y-5">
                    <h2 class="text-sm font-semibold">{{ l('resource_details') }}</h2>
                    <UFormField name="character_id" data-resource-field="character_id" :error="workspace.fieldError('character_id')" :label="l('author')" class="studio-form-row">
                        <div class="flex min-w-0 items-center gap-2">
                            <UAvatar :src="authorAvatar || undefined" :alt="draft.author" size="xs" />
                            <USelect v-model="author" :items="authors" size="sm" class="min-w-0 flex-1" />
                        </div>
                    </UFormField>
                    <dl class="studio-form-row text-xs">
                        <dt class="text-muted">{{ l('last_edit') }}</dt>
                        <dd><time v-if="resource" :datetime="resource.updatedAt">{{ date(resource.updatedAt) }}</time></dd>
                    </dl>
                    <UFormField name="collection_id" data-resource-field="collection_id" :error="workspace.fieldError('collection_id')" :label="l('collection')" class="studio-form-row">
                        <USelect v-model="folder" :items="folders" :disabled="resource?.isHome" size="sm" class="w-full">
                            <template #default><span class="flex min-w-0 items-center gap-2"><UIcon name="i-lucide-folder" class="size-4 shrink-0 text-[#d5b168]" /><UIcon v-if="selectedFolder && selectedFolder.icon !== 'i-lucide-folder'" :name="selectedFolder.icon" class="size-4 shrink-0" /><span class="truncate">{{ selectedFolder?.label }}</span></span></template>
                            <template #item-leading="{ item }"><UIcon name="i-lucide-folder" class="size-4 shrink-0 text-[#d5b168]" /><UIcon v-if="item.icon !== 'i-lucide-folder'" :name="item.icon" class="size-4 shrink-0" /></template>
                        </USelect>
                    </UFormField>
                    <ResourceImagePicker v-model="draft.cover" name="metadata_image_id" data-resource-field="metadata_image_id" :error="workspace.fieldError('metadata_image_id')" :label="l('cover_image')" compact />
                    <img v-if="draft.cover" :src="draft.cover" alt="" class="studio-resource-cover" />
                    <div class="space-y-3 border-t border-default pt-5">
                        <h3 class="text-sm font-semibold">{{ l('editors') }}</h3>
                        <div v-for="editor in editors" :key="editor.author" class="flex items-center gap-2 text-sm"><UAvatar :src="editor.authorAvatar" :alt="editor.author" size="xs" />{{ editor.author }}</div>
                    </div>
                </div>
                <ResourceEmbedList v-else-if="workspace.state.inspectorTab === 'discord'" :workspace="workspace" />
                <div v-else class="space-y-5">
                    <h2 class="text-sm font-semibold">{{ l('edit_history') }}</h2>
                    <UTimeline v-if="historyItems.length" :items="historyItems" size="sm" :ui="{ date: 'text-xs text-muted', title: 'text-sm font-medium', description: 'text-xs text-muted' }">
                        <template #description="{ item }">
                            <p>{{ item.description }}</p>
                            <UButton v-if="item.kind !== 'publication'" class="mt-2 rounded-none" icon="i-lucide-history" color="neutral" variant="outline" size="xs" :label="l('use_version')" :disabled="workspace.busy" @click="workspace.useRevision(item.id)" />
                            <p v-if="workspace.state.sourceRevisionId === item.id" class="mt-2 text-primary">{{ l('version_loaded') }}</p>
                        </template>
                    </UTimeline>
                    <p v-if="!resource?.history.length" class="text-sm text-muted">{{ l('no_history') }}</p>
                </div>
            </div>
        </template>
        <template v-else-if="resource">
            <header class="flex items-center justify-between gap-2 border-b border-default px-4 py-4"><h2 class="text-sm font-semibold">{{ l('resource_details') }}</h2><UBadge :color="resource.status === 'published' ? 'success' : 'neutral'" variant="subtle" size="sm">{{ l(resource.status) }}</UBadge></header>
            <div class="space-y-5 p-4">
                <div class="flex items-start gap-3">
                    <img v-if="resource.cover" :src="resource.cover" alt="" class="size-14 shrink-0 border border-default bg-muted object-contain" />
                    <div class="min-w-0"><h3 class="break-words text-lg font-semibold">{{ resource.title }}</h3><p class="mt-2 text-xs leading-relaxed text-muted">{{ resource.description }}</p></div>
                </div>
                <div class="flex gap-2">
                    <UButton icon="i-lucide-pencil" :label="l('edit_resource')" class="flex-1 justify-center" @click="workspace.edit(resource.id)" />
                    <UButton v-if="workspaceHasUnpublishedChanges(resource)" icon="i-lucide-check" :label="l('publish')" :disabled="workspace.busy || !resource.canPublish" :title="resource.canPublish ? undefined : l('save_before_publish')" class="flex-1 justify-center" @click="workspace.publish([resource.id])" />
                    <UTooltip :text="l('view')"><UButton icon="i-lucide-external-link" color="neutral" variant="outline" :aria-label="l('view')" :to="workspace.viewUrl(resource)" :disabled="!workspace.viewUrl(resource)" target="_blank" rel="noopener noreferrer" /></UTooltip>
                </div>
                <dl class="space-y-3 border-t border-default pt-5 text-xs">
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('collection') }}</dt><dd class="break-words">{{ workspace.state.collections.find(item => item.id === resource.collectionId)?.name ?? l('unfiled') }}</dd></div>
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('activities') }}</dt><dd>{{ resource.activities.join(', ') || l('none') }}</dd></div>
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('access') }}</dt><dd>{{ l(resource.access) }}</dd></div>
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('tags') }}</dt><dd class="flex flex-wrap gap-1"><UBadge v-for="tag in resource.tags" :key="tag" color="neutral" variant="soft" size="sm">{{ tag }}</UBadge></dd></div>
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('author') }}</dt><dd>{{ resource.author }}</dd></div>
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('edited') }}</dt><dd>{{ date(resource.updatedAt) }}</dd></div>
                </dl>
                <section class="space-y-3 border-t border-default pt-5">
                    <h3 class="text-xs font-semibold uppercase text-muted">{{ l('discord_embeds') }}</h3>
                    <div v-for="(embed, index) in resource.embeds" :key="index" class="space-y-2"><p class="font-mono text-xs text-primary">/info {{ embed.command }}</p><ResourceEmbedPreview :document="resource" :embed="workspace.embedPreview(resource, embed)" :public-resource="workspace.library?.visibility === 'public'" :resource-url="workspace.viewUrl(resource)" /></div>
                    <p v-if="!resource.embeds.length" class="text-xs text-dimmed">{{ l('no_embed') }}</p>
                </section>
                <section class="space-y-3 border-t border-default pt-5"><h3 class="text-xs font-semibold uppercase text-muted">{{ l('last_edit') }}</h3><template v-if="resource.history[0]"><p class="text-xs font-medium">{{ resource.history[0].author }}</p><p class="text-xs leading-relaxed text-muted">{{ resource.history[0].summary }}</p></template><p v-else class="text-xs text-muted">{{ l('no_history') }}</p></section>
            </div>
        </template>
        <div v-else class="flex min-h-64 flex-col items-center justify-center gap-3 p-6 text-center text-sm text-muted"><UIcon name="i-lucide-mouse-pointer-2" class="size-6" />{{ l('select_resource') }}</div>
    </aside>
</template>

<style scoped>
.studio-inspector { display: flex; flex-direction: column; min-width: 0; min-height: 0; background: transparent; }
.studio-inspector-tabs { flex: none; border-bottom: 1px solid var(--ui-border); }
.studio-inspector-content { padding: 14px 12px 18px; min-width: 0; }
.studio-inspector :deep(.studio-form-row) { display: grid; grid-template-columns: 76px minmax(0, 1fr); align-items: start; gap: 12px; }
.studio-inspector :deep(.studio-form-row > div:last-child) { margin-top: 0; min-width: 0; }
.studio-inspector :deep(.studio-form-row label) { display: block; padding-top: 6px; font-size: 12px; line-height: 1.4; font-weight: 400; color: var(--ui-text-muted); }
.studio-inspector :deep(.studio-form-row input), .studio-inspector :deep(.studio-form-row textarea), .studio-inspector :deep(.studio-form-row button) { font-size: 12px; }
.studio-resource-cover { width: 100%; max-height: 220px; object-fit: contain; border: 1px solid var(--ui-border); background: var(--ui-bg-elevated); }
</style>
