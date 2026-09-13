<script setup lang="ts">
import type { ResourceReaderDocument } from '@/Types/GroupResources'
import type { RichTextDocument } from '@/Types/RichText'
import RichTextReader from '@/components/Shared/RichText/RichTextReader.vue'
import { resourceContentExtensions } from './resourceContentExtensions'
import { useI18n } from 'vue-i18n'
import { localizedValue } from '@/utils/localizedValue'
import { readerDate } from '@/utils/resourceReader'
import ResourceHolsterLoadout from './ResourceHolsterLoadout.vue'
import ReportButton from '@/components/Shared/Reports/ReportButton.vue'

withDefaults(defineProps<{ resource: ResourceReaderDocument; document?: RichTextDocument; activities?: { id: number; name: Record<string, string> }[] }>(), { activities: () => [] })
defineEmits<{ tag: [tag: string]; image: [uuid: string] }>()
const { t, locale } = useI18n()
const contentExtensions = resourceContentExtensions()
</script>

<template>
    <article class="resource-reader-article min-w-0 space-y-6">
        <header class="resource-article-header" :class="resource.metadata_image_id ? 'relative isolate flex min-h-64 flex-col justify-end overflow-hidden border border-default bg-neutral-950 sm:min-h-80' : 'border-b border-default pb-6'">
            <template v-if="resource.metadata_image_id">
                <img :src="`/resource-assets/${resource.metadata_image_id}`" :alt="resource.images?.find(image => image.uuid === resource.metadata_image_id)?.alt_text || ''" class="pointer-events-none absolute inset-0 size-full object-cover" />
                <div class="resource-cover-gradient pointer-events-none absolute inset-0" aria-hidden="true" />
            </template>
            <div class="relative min-w-0" :class="resource.metadata_image_id ? 'px-5 pb-5 pt-24 sm:px-7 sm:pb-7 sm:pt-28' : ''">
                <div v-if="resource.tags.length || resource.activity_type_ids.length || resource.access_level !== 'everyone'" class="mb-4 flex flex-wrap items-center gap-2">
                    <UButton v-for="tag in resource.tags" :key="tag" size="xs" :color="resource.metadata_image_id ? 'neutral' : 'primary'" variant="soft" :class="resource.metadata_image_id ? 'bg-black/45 text-white ring-1 ring-inset ring-white/20 hover:bg-black/65' : ''" :label="tag" @click="$emit('tag', tag)" />
                    <UBadge v-for="activity in activities.filter(item => resource.activity_type_ids.includes(item.id))" :key="activity.id" color="neutral" variant="subtle" :class="resource.metadata_image_id ? 'bg-black/45 text-white ring-white/20' : ''">{{ localizedValue(activity.name, locale) }}</UBadge>
                    <UBadge v-if="resource.access_level !== 'everyone'" icon="i-lucide-lock-keyhole" color="neutral" variant="outline" :class="resource.metadata_image_id ? 'bg-black/45 text-white ring-white/20' : ''">{{ t(`groups.resources.reader.access_${resource.access_level}`) }}</UBadge>
                </div>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h1 class="min-w-0 flex-1 break-words text-2xl font-semibold tracking-tight sm:text-3xl" :class="resource.metadata_image_id ? 'text-white' : 'text-highlighted'">{{ resource.title }}</h1>
                    <ReportButton :target="{ type: resource.holster_id ? 'holster' : 'resource', id: resource.holster_id ?? resource.id, label: resource.title }" :entry-url="resource.report_url" :class="resource.metadata_image_id ? 'bg-black/45 text-white ring-white/30 hover:bg-black/65' : ''" />
                </div>
                <p v-if="resource.description" class="mt-3 break-words leading-relaxed" :class="resource.metadata_image_id ? 'text-white/85' : 'text-muted'">{{ resource.description }}</p>
                <div v-if="resource.author || resource.published_at" class="mt-5 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs" :class="resource.metadata_image_id ? 'text-white/70' : 'text-muted'">
                    <span v-if="resource.author" class="flex items-center gap-2"><UAvatar :src="resource.author.avatar_url || undefined" :alt="resource.author.name" size="2xs" />{{ resource.author.name }}</span>
                    <time v-if="resource.published_at" :datetime="resource.published_at">{{ t('groups.resources.reader.updated', { date: readerDate(resource.published_at, locale) }) }}</time>
                </div>
                <UButton v-if="resource.metadata_image_id && resource.images.some(image => image.uuid === resource.metadata_image_id)" class="mt-3 bg-black/45 text-white ring-white/30 hover:bg-black/65" color="neutral" variant="outline" size="xs" icon="i-lucide-image" :label="t('reports.view_image')" @click="$emit('image', resource.metadata_image_id)" />
            </div>
        </header>
        <ResourceHolsterLoadout v-if="resource.holster" :holster="resource.holster" />
        <div v-if="resource.legacy_body_html" class="rich-text-content prose max-w-none dark:prose-invert" v-html="resource.legacy_body_html" />
        <RichTextReader v-else :document="document ?? resource.body" :additional-extensions="contentExtensions" />
    </article>
</template>

<style scoped>
.resource-cover-gradient {
    background: linear-gradient(180deg, rgb(9 9 15 / 12%) 0%, rgb(9 9 15 / 55%) 35%, rgb(9 9 15 / 88%) 68%, rgb(9 9 15 / 97%) 100%);
}
</style>
