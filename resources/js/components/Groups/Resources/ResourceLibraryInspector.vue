<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController } from '@/Types/ResourceWorkspace'
import { workspaceCollectionPath, workspaceHasUnpublishedChanges } from '@/utils/resourceWorkspace'
import ResourceEmbedPreview from './ResourceEmbedPreview.vue'
import ResourcePinButton from './ResourcePinButton.vue'
const props = defineProps<{ workspace: ResourceWorkspaceController }>()
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const resource = computed(() => props.workspace.selected)
const historyItems = computed(() => (resource.value?.history ?? []).slice(0, 2).map(revision => ({
    value: revision.id,
    date: date(revision.at),
    title: revision.summary,
    description: revision.author,
    avatar: { src: revision.authorAvatar, alt: revision.author },
})))
const actions = computed(() => resource.value ? [
    { label: l('move'), icon: 'i-lucide-folder-input', disabled: resource.value.isHome, onSelect: () => props.workspace.openMove([resource.value!.id]) },
    { label: l('delete'), icon: 'i-lucide-trash-2', color: 'error' as const, disabled: resource.value.isHome || !!resource.value.holsterId, onSelect: () => props.workspace.remove(resource.value!.id) },
    { label: l(resource.value.status === 'archived' ? 'restore_draft' : 'archive'), icon: resource.value.status === 'archived' ? 'i-lucide-archive-restore' : 'i-lucide-archive', disabled: resource.value.isHome, onSelect: () => resource.value?.status === 'archived' ? props.workspace.unarchive(resource.value.id) : props.workspace.archive(resource.value!.id) },
] : [])
function date(value: string) { return new Date(value).toLocaleString(locale.value, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }) }
</script>

<template>
    <aside class="library-inspector">
        <template v-if="resource">
            <header class="inspector-heading"><h2>{{ resource.title }}</h2><UBadge :color="resource.status === 'published' ? 'success' : 'neutral'" variant="subtle" size="sm" class="inspector-status">{{ l(resource.status) }}</UBadge><UDropdownMenu :items="actions"><UButton icon="i-lucide-ellipsis" color="neutral" variant="ghost" size="xs" :aria-label="l('actions')" /></UDropdownMenu></header>
            <div class="inspector-overview">
                <a v-if="resource.cover" class="inspector-cover" :aria-label="l('view')" :href="workspace.viewUrl(resource)" :aria-disabled="!workspace.viewUrl(resource)" target="_blank" rel="noopener noreferrer"><img :src="resource.cover" alt="" /><span class="cover-expand"><UIcon name="i-lucide-external-link" /></span></a>
                <p class="resource-description">{{ resource.description }}</p>
                <div class="inspector-actions"><UButton v-if="resource.status === 'archived'" icon="i-lucide-archive-restore" color="neutral" variant="solid" :label="l('restore_draft')" @click="workspace.unarchive(resource.id)" /><UButton v-else icon="i-lucide-pencil" color="neutral" variant="solid" :label="l('edit_resource')" @click="workspace.edit(resource.id)" /><UButton v-if="workspaceHasUnpublishedChanges(resource)" icon="i-lucide-check" :label="l('publish')" :disabled="workspace.busy || !resource.canPublish" :title="resource.canPublish ? undefined : l('save_before_publish')" @click="workspace.publish([resource.id])" /><UButton icon="i-lucide-external-link" color="neutral" variant="outline" :label="l('view')" :to="workspace.viewUrl(resource)" :disabled="!workspace.viewUrl(resource)" target="_blank" rel="noopener noreferrer" /><UButton v-if="!resource.isHome && resource.status !== 'archived'" icon="i-lucide-archive" color="neutral" variant="outline" :label="l('archive')" @click="workspace.archive(resource.id)" /><UDropdownMenu :items="actions"><UButton icon="i-lucide-ellipsis" color="neutral" variant="outline" :aria-label="l('actions')" /></UDropdownMenu></div>
                <div class="mt-2"><ResourcePinButton :workspace="workspace" :resource="resource" /></div>
                <dl class="inspector-metadata">
                    <div><dt>{{ l('collection') }}</dt><dd><UIcon name="i-lucide-folder" /><span>{{ workspaceCollectionPath(workspace.state.collections, resource.collectionId) || l('unfiled') }}</span></dd></div>
                    <div><dt>{{ l('activities') }}</dt><dd><UIcon name="i-lucide-swords" /><span>{{ resource.activities.join(', ') || l('none') }}</span></dd></div>
                    <div><dt>{{ l('access') }}</dt><dd><UIcon :name="resource.access === 'everyone' ? 'i-lucide-globe' : 'i-lucide-lock-keyhole'" /><span>{{ l(resource.access) }}</span></dd></div>
                    <div><dt>{{ l('tags') }}</dt><dd><UIcon name="i-lucide-tag" /><span class="metadata-tags"><UBadge v-for="tag in resource.tags" :key="tag" color="primary" variant="soft" size="md">{{ tag }}</UBadge></span></dd></div>
                    <div><dt>{{ l('author') }}</dt><dd><UAvatar :src="resource.authorAvatar" :alt="resource.author" class="author-avatar" /><span>{{ resource.author }}</span></dd></div>
                    <div><dt>{{ l('updated') }}</dt><dd><UIcon name="i-lucide-calendar-days" /><span>{{ date(resource.updatedAt) }}</span></dd></div>
                </dl>
            </div>
            <section class="inspector-discord">
                <header>
                    <h3>{{ l('discord_embeds') }}</h3>
                    <UButton icon="i-lucide-settings" color="neutral" variant="outline" size="sm" :label="l('configure')" :disabled="resource.status === 'archived'" @click="workspace.edit(resource.id, 'discord')" />
                </header>
                <div v-for="(embed, index) in resource.embeds" :key="index" class="mb-4 space-y-2" :class="{ 'border-l-2 border-primary pl-3': workspace.state.inspectorTab === 'discord' && workspace.state.embedIndex === index }">
                    <div class="flex items-center justify-between gap-2"><pre class="resource-embed-command min-w-0 flex-1"><code>/info {{ embed.command }}</code></pre><UBadge v-if="!embed.enabled" color="neutral" variant="subtle">{{ l('disabled') }}</UBadge></div>
                    <ResourceEmbedPreview :document="resource" :embed="workspace.embedPreview(resource, embed)" :public-resource="workspace.library?.visibility === 'public'" compact :resource-url="workspace.viewUrl(resource)" />
                </div>
                <p v-if="!resource.embeds.length" class="text-sm text-muted">{{ l('no_embed') }}</p>
            </section>
            <section class="inspector-history">
                <header><h3>{{ l('history') }}</h3><UButton trailing-icon="i-lucide-arrow-up-right" color="primary" variant="link" size="sm" :label="l('view_history')" @click="workspace.state.historyOpen = true" /></header>
                <UTimeline
                    v-if="historyItems.length"
                    :items="historyItems"
                    orientation="vertical"
                    size="sm"
                    color="neutral"
                    :ui="{
                        wrapper: 'min-w-0 pb-4',
                        date: 'text-xs text-muted',
                        title: 'text-sm font-medium leading-6 break-words',
                        description: 'text-sm text-muted break-words',
                        separator: 'bg-accented',
                    }"
                />
                <p v-else class="text-sm text-muted">{{ l('no_history') }}</p>
            </section>
        </template>
        <div v-else class="inspector-empty"><UIcon name="i-lucide-mouse-pointer-2" /><p>{{ l('select_resource') }}</p></div>
    </aside>
</template>

<style scoped>
.library-inspector { min-width: 0; padding: 0 16px 16px; background: transparent; font-size: 14px; }.inspector-heading { display: flex; align-items: center; gap: 10px; min-height: 52px; }.inspector-heading h2 { min-width: 0; flex: 1; font-size: 20px; font-weight: 600; overflow-wrap: anywhere; }.inspector-status { padding: 3px 10px; font-size: 12px; border-radius: 3px; }
.inspector-cover { position: relative; width: 100%; display: block; aspect-ratio: 3 / 1; overflow: hidden; border: 1px solid var(--ui-border-accented); border-radius: 3px; }.inspector-cover img { width: 100%; height: 100%; object-fit: cover; }.cover-expand { position: absolute; bottom: 4px; right: 4px; display: flex; padding: 4px; background: #141116cc; }.cover-expand > span { width: 15px; height: 15px; }
.resource-description { font-size: 14px; line-height: 1.6; margin: 8px 0 12px; }.inspector-actions { display: flex; gap: 6px; flex-wrap: wrap; }.inspector-actions > button:first-child { flex: 1; justify-content: center; }.inspector-metadata { display: grid; gap: 8px; margin: 16px 0 14px; font-size: 14px; }.inspector-metadata > div { display: grid; grid-template-columns: 88px minmax(0, 1fr); gap: 12px; min-height: 20px; align-items: start; }.inspector-metadata dt { color: var(--ui-text-muted); }.inspector-metadata dd { display: flex; align-items: center; gap: 10px; min-width: 0; }.inspector-metadata dd > span:first-child:not(.metadata-tags) { width: 18px; height: 18px; flex: none; }.inspector-metadata dd > span:last-child { min-width: 0; overflow-wrap: anywhere; }.metadata-tags { display: flex; flex-wrap: wrap; gap: 5px; }.author-avatar { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; flex: none; }
.inspector-discord, .inspector-history { border-top: 1px solid var(--ui-border); padding-top: 14px; margin-top: 16px; }.inspector-discord > header, .inspector-history > header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; }.library-inspector h3 { font-size: 16px; font-weight: 600; }.resource-embed-command { margin: 0 0 12px; padding: 10px 12px; border: 1px solid var(--ui-border); border-radius: 3px; background: var(--ui-bg-elevated); font-family: var(--font-mono); font-size: 13px; line-height: 1.5; color: var(--ui-text); white-space: pre-wrap; overflow-wrap: anywhere; }.inspector-empty { display: flex; min-height: 240px; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: var(--ui-text-muted); }
</style>
