<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController } from '@/Types/ResourceWorkspace'
import { MAX_RESOURCE_EMBEDS } from '@/utils/resourceWorkspace'
import ResourceEmbedPreview from './ResourceEmbedPreview.vue'

const props = defineProps<{ workspace: ResourceWorkspaceController }>()
const { t } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const draft = computed(() => props.workspace.state.draft)
</script>

<template>
    <div v-if="draft" class="space-y-4">
        <p v-if="workspace.fieldError('commands')" data-resource-field="commands" role="alert" class="text-sm text-error">{{ workspace.fieldError('commands') }}</p>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold">{{ l('discord_embeds') }} <span class="ml-1 text-xs font-normal text-muted">{{ draft.embeds.length }}/{{ MAX_RESOURCE_EMBEDS }}</span></h2>
            <UButton icon="i-lucide-plus" color="neutral" variant="outline" size="sm" :label="l('add_embed')" :disabled="workspace.busy || draft.embeds.length >= MAX_RESOURCE_EMBEDS" @click="workspace.addEmbed()" />
        </div>
        <section v-for="(embed, index) in draft.embeds" :key="index" class="space-y-3 border-b border-default pb-5 last:border-b-0" :class="{ 'border-l-2 border-l-primary pl-3': workspace.state.editorPane === 'embed' && workspace.state.embedIndex === index }">
            <div class="flex items-start gap-1">
                <pre class="min-w-0 flex-1 whitespace-pre-wrap break-words border border-default bg-elevated px-3 py-2 font-mono text-xs"><code>/info {{ embed.command || '...' }}</code></pre>
                <UTooltip :text="l('edit_embed')"><UButton icon="i-lucide-pencil" color="neutral" variant="outline" :aria-label="l('edit_embed')" :disabled="workspace.busy" @click="workspace.state.selectedId && workspace.openEmbed(workspace.state.selectedId, index)" /></UTooltip>
                <UTooltip :text="l('delete_embed')"><UButton icon="i-lucide-trash-2" color="error" variant="outline" :aria-label="l('delete_embed')" :disabled="workspace.busy" @click="workspace.removeEmbed(index)" /></UTooltip>
            </div>
            <ResourceEmbedPreview :document="draft" :embed="workspace.embedPreview(draft, embed)" :public-resource="workspace.library?.visibility === 'public'" compact :resource-url="workspace.viewUrl()" />
        </section>
        <p v-if="!draft.embeds.length" class="text-sm text-muted">{{ l('no_embed') }}</p>
    </div>
</template>
