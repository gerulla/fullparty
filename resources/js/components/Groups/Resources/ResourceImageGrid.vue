<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ResourceImage, ResourceImagesController } from '@/Types/ResourceImages'
import { formatBytes } from '@/utils/formatBytes'
defineProps<{ images: ResourceImagesController; selected?: string }>()
const emit = defineEmits<{ select: [image: ResourceImage] }>()
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.uploads.${key}`)
</script>

<template>
    <div class="flex min-h-0 min-w-0 flex-1 flex-col">
        <div class="flex flex-wrap items-center gap-2 border-b border-default p-3" :inert="images.state.busy">
            <UInput v-model="images.state.query" icon="i-lucide-search" :placeholder="l('search')" :aria-label="l('search')" class="min-w-40 flex-1" @update:model-value="images.search()" />
            <USelect v-model="images.state.type" :items="[{ label: l('all'), value: 'all' }, { label: l('images'), value: 'image' }, { label: 'GIF', value: 'gif' }]" :aria-label="l('type')" class="w-32" @update:model-value="images.load()" />
            <slot name="actions" />
        </div>
        <UAlert v-if="images.state.error" :title="images.state.error" color="error" variant="soft" class="m-3" />
        <div v-if="images.state.loading" class="flex min-h-48 flex-1 items-center justify-center" role="status"><UIcon name="i-lucide-loader-circle" class="size-6 animate-spin" :aria-label="t('general.loading')" /></div>
        <div v-else-if="!images.state.items.length" class="flex min-h-48 flex-1 flex-col items-center justify-center gap-3 p-6 text-center text-muted"><UIcon name="i-lucide-images" class="size-8" /><p>{{ images.state.query || images.state.type !== 'all' ? l('no_results') : l('empty') }}</p></div>
        <div v-else class="min-h-0 flex-1 overflow-y-auto p-3">
            <div class="image-grid">
                <button v-for="image in images.state.items" :key="image.uuid" type="button" class="image-tile border border-default text-left hover:bg-elevated focus-visible:outline-2 focus-visible:outline-primary" :class="{ 'ring-2 ring-primary': selected === image.uuid }" :aria-pressed="selected === image.uuid" :title="image.name" :disabled="images.state.busy" @click="emit('select', image)">
                    <div class="relative flex aspect-[4/3] items-center justify-center overflow-hidden bg-muted"><img :src="image.url" :alt="image.alt_text" loading="lazy" class="size-full object-contain" /><UBadge v-if="image.mime_type === 'image/gif'" color="neutral" size="sm" class="absolute right-1 bottom-1">GIF</UBadge></div>
                    <div class="min-w-0 px-2 py-2"><p class="truncate text-sm font-medium">{{ image.name }}</p><p class="mt-1 text-xs text-muted">{{ image.width }} &times; {{ image.height }} &middot; {{ formatBytes(image.size_bytes, locale) }}</p></div>
                </button>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-default p-3"><span class="text-sm text-muted">{{ l('count') }}: {{ images.state.total }}</span><UPagination :page="images.state.page" :total="images.state.total" :items-per-page="images.state.perPage" :sibling-count="0" :disabled="images.state.loading || images.state.busy" @update:page="images.load($event)" /></div>
    </div>
</template>

<style scoped>
.image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(150px, 100%), 1fr)); gap: 12px; }
.image-tile { min-width: 0; overflow: hidden; border-radius: 0; }
</style>
