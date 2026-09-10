<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkspaceDocument } from '@/Types/ResourceWorkspace'
import { resourceEmbedFieldRows, resourceEmbedImage, resourceEmbedLink } from '@/utils/resourceEmbedPreview'
import ResourceEmbedMarkdown from './ResourceEmbedMarkdown.vue'

const props = defineProps<{ document: WorkspaceDocument; compact?: boolean }>()
defineEmits<{ open: [] }>()
const { t, locale } = useI18n()
const embed = computed(() => props.document.embed)
const titleUrl = computed(() => resourceEmbedLink(embed.value.url))
const authorUrl = computed(() => resourceEmbedLink(embed.value.authorUrl))
const authorIcon = computed(() => resourceEmbedImage(embed.value.authorIcon))
const thumbnail = computed(() => resourceEmbedImage(embed.value.thumbnail))
const mainImage = computed(() => resourceEmbedImage(embed.value.image))
const fieldRows = computed(() => resourceEmbedFieldRows(embed.value.fields, Boolean(thumbnail.value)))
const timestamp = computed(() => {
    if (!embed.value.timestamp) return ''
    const date = new Date(embed.value.timestamp)
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' })
})
</script>

<template>
    <div class="discord-embed-preview">
        <article class="discord-embed" :style="{ borderLeftColor: /^#[0-9a-f]{6}$/i.test(embed.color) ? embed.color : '#202225' }">
            <div class="discord-embed-grid" :class="{ 'has-thumbnail': thumbnail }">
                <div v-if="embed.author" class="discord-embed-author">
                    <img v-if="authorIcon" :src="authorIcon" alt="" />
                    <a v-if="authorUrl" :href="authorUrl" target="_blank" rel="noopener noreferrer">{{ embed.author }}</a>
                    <span v-else>{{ embed.author }}</span>
                </div>
                <div v-if="embed.title" class="discord-embed-title">
                    <a v-if="titleUrl" :href="titleUrl" target="_blank" rel="noopener noreferrer">{{ embed.title }}</a>
                    <span v-else>{{ embed.title }}</span>
                </div>
                <ResourceEmbedMarkdown v-if="embed.description" :content="embed.description" class="discord-embed-description" />
                <img v-if="thumbnail" :src="thumbnail" alt="" class="discord-embed-thumbnail" />
                <div v-if="fieldRows.length" class="discord-embed-fields">
                    <div v-for="(row, rowIndex) in fieldRows" :key="rowIndex" class="discord-embed-field-row" :style="{ gridTemplateColumns: 'repeat(' + row.length + ', minmax(0, 1fr))' }">
                        <div v-for="(field, index) in row" :key="index" class="discord-embed-field">
                            <div class="discord-embed-field-name">{{ field.name }}</div>
                            <ResourceEmbedMarkdown :content="field.value" />
                        </div>
                    </div>
                </div>
                <img v-if="mainImage" :src="mainImage" alt="" class="discord-embed-image" />
                <footer class="discord-embed-footer"><span>FullParty</span><span v-if="timestamp">&bull; {{ timestamp }}</span></footer>
            </div>
        </article>
        <UButton v-if="document.access === 'everyone'" trailing-icon="i-lucide-external-link" color="neutral" variant="solid" size="sm" class="discord-resource-link" :label="t('groups.resources.workspace.open_resource')" @click="$emit('open')" />
    </div>
</template>

<style scoped>
.discord-embed-preview { min-width: 0; max-width: 520px; font-family: 'gg sans', 'Noto Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.375; color: #dbdee1; }
.discord-embed { min-width: 0; padding: 12px 16px 16px 12px; border-left: 4px solid; border-radius: 4px; background: #2b2d31; }
.discord-embed-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px 16px; }
.discord-embed-grid.has-thumbnail { grid-template-columns: minmax(0, 1fr) 80px; }
.discord-embed-author, .discord-embed-title, .discord-embed-description, .discord-embed-fields { grid-column: 1; min-width: 0; overflow-wrap: anywhere; }
.discord-embed-author { display: flex; align-items: center; gap: 8px; color: #f2f3f5; font-weight: 600; }
.discord-embed-author img { width: 24px; height: 24px; flex: none; border-radius: 50%; object-fit: cover; }
.discord-embed-author a { color: inherit; }
.discord-embed-title { font-size: 16px; font-weight: 600; color: #f2f3f5; }
.discord-embed-title a { color: #00a8fc; }
.discord-embed-author a:hover, .discord-embed-title a:hover { text-decoration: underline; }
.discord-embed-thumbnail { grid-column: 2; grid-row: 1 / span 4; width: 80px; max-height: 80px; object-fit: contain; border-radius: 4px; justify-self: end; }
.discord-embed-fields { display: grid; gap: 8px; }
.discord-embed-field-row { display: grid; gap: 8px; }
.discord-embed-field { min-width: 0; }
.discord-embed-field-name { margin-bottom: 2px; font-weight: 600; color: #f2f3f5; }
.discord-embed-image { grid-column: 1 / -1; max-width: 100%; max-height: 300px; object-fit: contain; border-radius: 4px; }
.discord-embed-footer { grid-column: 1 / -1; display: flex; align-items: center; flex-wrap: wrap; gap: 4px; font-size: 12px; line-height: 16px; }
.discord-resource-link { margin-top: 8px; border-radius: 0; background: #4e5058; color: #f2f3f5; font-family: inherit; font-weight: 500; }
.discord-resource-link:hover { background: #6d6f78; }
</style>
