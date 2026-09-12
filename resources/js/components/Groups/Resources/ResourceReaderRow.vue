<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { ResourceReaderSummary } from '@/Types/GroupResources'
import { readerDate } from '@/utils/resourceReader'

withDefaults(defineProps<{ resource: ResourceReaderSummary; href: string; compact?: boolean; preview?: boolean }>(), { compact: false, preview: false })
const { t, locale } = useI18n()
</script>

<template>
    <component :is="preview ? 'div' : Link" :href="preview ? undefined : href" class="resource-reader-row group flex min-w-0 items-start gap-3 border-b border-default py-4 transition-colors hover:bg-elevated/50" :class="compact ? 'text-sm' : 'sm:gap-4'">
        <img v-if="resource.metadata_image_id && !compact" :src="`/resource-assets/${resource.metadata_image_id}`" alt="" loading="lazy" class="mt-0.5 hidden h-12 w-16 shrink-0 border border-default object-cover sm:block" />
        <UIcon v-else :name="resource.source_type === 'holster' ? 'i-lucide-backpack' : resource.access_level === 'everyone' ? 'i-lucide-file-text' : 'i-lucide-file-lock-2'" class="mt-1 size-4 shrink-0 text-primary" />
        <span class="min-w-0 flex-1">
            <span class="block break-words font-medium text-highlighted group-hover:text-primary">{{ resource.title }}</span>
            <span v-if="resource.description && !compact" class="mt-1 line-clamp-2 text-sm leading-relaxed text-muted">{{ resource.description }}</span>
            <span v-if="!compact && resource.tags.length" class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted"><span v-for="tag in resource.tags.slice(0, 3)" :key="tag">#{{ tag }}</span></span>
            <span v-if="compact && resource.published_at" class="mt-1 block text-xs text-muted">{{ readerDate(resource.published_at, locale) }}</span>
        </span>
        <time v-if="!compact && resource.published_at" :datetime="resource.published_at" class="mt-1 hidden shrink-0 text-xs text-muted xl:block">{{ readerDate(resource.published_at, locale) }}</time>
        <UIcon name="i-lucide-chevron-right" class="mt-1 size-4 shrink-0 text-dimmed group-hover:text-primary" :aria-label="t('groups.resources.reader.open_resource')" />
    </component>
</template>
