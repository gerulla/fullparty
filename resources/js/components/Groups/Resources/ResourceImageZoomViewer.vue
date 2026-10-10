<script setup lang="ts">
import { ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useImageViewerZoom } from '@/composables/useImageViewerZoom'

defineProps<{ src: string; alt: string }>()
const { t } = useI18n()
const viewport = ref<HTMLElement | null>(null)
const image = ref<HTMLImageElement | null>(null)
const hintId = useId()
const viewer = useImageViewerZoom(viewport, image)
const { zoom, minZoom, maxZoom, ready, dragging, canPan, imageStyle } = viewer
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3" role="group" :aria-label="t('groups.resources.image_viewer.zoom')">
            <div class="flex items-center gap-2">
                <UButton color="neutral" variant="outline" icon="i-lucide-zoom-out" :aria-label="t('groups.resources.image_viewer.zoom_out')" :title="t('groups.resources.image_viewer.zoom_out')" :disabled="!ready || zoom <= minZoom" @click="viewer.setZoom(zoom - 50)" />
                <span class="w-14 text-center text-sm font-medium tabular-nums" role="status" aria-live="polite" aria-atomic="true">{{ zoom }}%</span>
                <UButton color="neutral" variant="outline" icon="i-lucide-zoom-in" :aria-label="t('groups.resources.image_viewer.zoom_in')" :title="t('groups.resources.image_viewer.zoom_in')" :disabled="!ready || zoom >= maxZoom" @click="viewer.setZoom(zoom + 50)" />
            </div>
            <UButton color="neutral" variant="ghost" icon="i-lucide-scan" :label="t('groups.resources.image_viewer.fit')" :disabled="!ready" @click="viewer.reset" />
        </div>
        <div
            ref="viewport"
            tabindex="0"
            role="region"
            :aria-label="alt || t('reports.image_preview')"
            :aria-describedby="hintId"
            class="relative flex h-[60dvh] select-none items-center justify-center overflow-hidden bg-elevated/50 outline-none ring-1 ring-default focus-visible:ring-2 focus-visible:ring-primary"
            :class="canPan ? (dragging ? 'cursor-grabbing touch-none' : 'cursor-grab touch-none') : 'touch-pan-y'"
            @wheel.prevent="viewer.onWheel"
            @pointerdown="viewer.onPointerDown"
            @pointermove="viewer.onPointerMove"
            @pointerup="viewer.stopDragging"
            @pointercancel="viewer.stopDragging"
            @lostpointercapture="viewer.stopDragging"
            @keydown="viewer.onKeydown"
        >
            <img ref="image" :src="src" :alt="alt" draggable="false" class="pointer-events-none max-w-none shrink-0 select-none object-contain" :style="imageStyle" @load="viewer.imageLoaded" />
        </div>
        <p :id="hintId" class="text-xs text-muted">{{ t('groups.resources.image_viewer.hint') }}</p>
    </div>
</template>
