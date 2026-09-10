<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController } from '@/Types/ResourceWorkspace'
import ResourceDiscordEditor from './ResourceDiscordEditor.vue'
import ResourceEmbedPreview from './ResourceEmbedPreview.vue'
import ResourceImagePicker from './ResourceImagePicker.vue'

const props = defineProps<{ workspace: ResourceWorkspaceController }>()
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const resource = computed(() => props.workspace.selected)
const draft = computed(() => props.workspace.state.draft)
const tabs = computed(() => [
    { label: l('resource'), value: 'resource' },
    { label: l('discord'), value: 'discord' },
    { label: l('history'), value: 'history' },
])
const folders = computed(() => [{ value: 'root', label: l('unfiled') }, ...props.workspace.state.collections.map(item => ({ value: item.id, label: item.name }))])
const folder = computed({ get: () => draft.value?.collectionId ?? 'root', set: value => { if (draft.value) draft.value.collectionId = value === 'root' ? null : value } })
function date(value: string) { return new Date(value).toLocaleString(locale.value, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) }
const editors = computed(() => [...new Map(resource.value?.history.map(item => [item.author, item]) ?? []).values()])
const historyItems = computed(() => resource.value?.history.map(item => ({ date: date(item.at), title: item.summary, description: item.author, avatar: { src: item.authorAvatar, alt: item.author } })) ?? [])
</script>

<template>
    <aside class="studio-inspector">
        <template v-if="workspace.state.mode === 'editor' && draft">
            <UTabs v-model="workspace.state.inspectorTab" :items="tabs" :content="false" variant="link" size="sm" class="studio-inspector-tabs" :ui="{ list: 'w-full justify-start rounded-none px-3', trigger: 'flex-none rounded-none px-4 py-3', indicator: 'rounded-none' }" />
            <div class="studio-inspector-content">
                <div v-if="workspace.state.inspectorTab === 'resource'" class="space-y-5">
                    <h2 class="text-sm font-semibold">{{ l('resource_details') }}</h2>
                    <UFormField :label="l('collection')" class="studio-form-row"><USelect v-model="folder" :items="folders" size="sm" class="w-full" /></UFormField>
                    <ResourceImagePicker v-model="draft.cover" :label="l('cover_image')" compact />
                    <img v-if="draft.cover" :src="draft.cover" alt="" class="studio-resource-cover" />
                    <div class="space-y-3 border-t border-default pt-5">
                        <h3 class="text-sm font-semibold">{{ l('editors') }}</h3>
                        <div v-for="editor in editors" :key="editor.author" class="flex items-center gap-2 text-sm"><UAvatar :src="editor.authorAvatar" :alt="editor.author" size="xs" />{{ editor.author }}</div>
                    </div>
                </div>
                <ResourceDiscordEditor v-else-if="workspace.state.inspectorTab === 'discord'" :document="draft" @preview="workspace.preview()" />
                <div v-else class="space-y-5">
                    <h2 class="text-sm font-semibold">{{ l('edit_history') }}</h2>
                    <UTimeline v-if="historyItems.length" :items="historyItems" size="sm" :ui="{ date: 'text-xs text-muted', title: 'text-sm font-medium', description: 'text-xs text-muted' }" />
                    <p v-if="!resource?.history.length" class="text-sm text-muted">{{ l('no_history') }}</p>
                </div>
            </div>
        </template>
        <template v-else-if="resource">
            <header class="flex items-center justify-between gap-2 border-b border-default px-4 py-4"><h2 class="text-sm font-semibold">{{ l('resource_details') }}</h2><UBadge :color="resource.status === 'published' ? 'success' : resource.status === 'pending' ? 'warning' : 'neutral'" variant="subtle" size="sm">{{ l(resource.status) }}</UBadge></header>
            <div class="space-y-5 p-4">
                <div class="flex items-start gap-3">
                    <img v-if="resource.cover" :src="resource.cover" alt="" class="size-14 shrink-0 border border-default bg-muted object-contain" />
                    <div class="min-w-0"><h3 class="break-words text-lg font-semibold">{{ resource.title }}</h3><p class="mt-2 text-xs leading-relaxed text-muted">{{ resource.description }}</p></div>
                </div>
                <div class="flex gap-2">
                    <UButton v-if="resource.status !== 'pending'" icon="i-lucide-pencil" :label="l('edit_resource')" class="flex-1 justify-center" @click="workspace.edit(resource.id)" />
                    <UButton v-else icon="i-lucide-check" :label="l('publish')" class="flex-1 justify-center" @click="workspace.publish([resource.id])" />
                    <UTooltip :text="l('preview')"><UButton icon="i-lucide-eye" color="neutral" variant="outline" :aria-label="l('preview')" @click="workspace.preview(resource)" /></UTooltip>
                </div>
                <div v-if="resource.status === 'pending'" class="space-y-2 border-l-2 border-warning pl-3"><p class="text-xs leading-relaxed text-warning">{{ l('pending_lock') }}</p><UButton icon="i-lucide-trash-2" variant="solid" color="error" size="sm" :label="l('discard_pending')" @click="workspace.discardPending(resource.id)" /></div>
                <dl class="space-y-3 border-t border-default pt-5 text-xs">
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('collection') }}</dt><dd class="break-words">{{ workspace.state.collections.find(item => item.id === resource.collectionId)?.name ?? l('unfiled') }}</dd></div>
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('activities') }}</dt><dd>{{ resource.activities.join(', ') || l('none') }}</dd></div>
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('access') }}</dt><dd>{{ l(resource.access) }}</dd></div>
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('tags') }}</dt><dd class="flex flex-wrap gap-1"><UBadge v-for="tag in resource.tags" :key="tag" color="neutral" variant="soft" size="sm">{{ tag }}</UBadge></dd></div>
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('author') }}</dt><dd>{{ resource.author }}</dd></div>
                    <div class="grid grid-cols-[5rem_minmax(0,1fr)] gap-2"><dt class="text-dimmed">{{ l('edited') }}</dt><dd>{{ date(resource.updatedAt) }}</dd></div>
                </dl>
                <section class="space-y-3 border-t border-default pt-5">
                    <div class="flex items-center justify-between gap-2"><h3 class="text-xs font-semibold uppercase text-muted">{{ l('discord_command') }}</h3><UIcon :name="resource.embed.enabled ? 'i-lucide-circle-check' : 'i-lucide-circle-minus'" :class="resource.embed.enabled ? 'text-success' : 'text-dimmed'" class="size-4" :aria-label="l(resource.embed.enabled ? 'enabled' : 'disabled')" /></div>
                    <template v-if="resource.embed.enabled"><p class="font-mono text-xs text-primary">/info {{ resource.embed.command }}</p><ResourceEmbedPreview :document="resource" @open="workspace.preview(resource)" /></template>
                    <p v-else class="text-xs text-dimmed">{{ l('disabled') }}</p>
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
