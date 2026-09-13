<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ResourceReaderImage } from '@/Types/ResourceImages'
import ReportButton from '@/components/Shared/Reports/ReportButton.vue'

defineProps<{ image: ResourceReaderImage | null }>()
const open = defineModel<boolean>('open', { required: true })
const { t } = useI18n()
</script>

<template>
    <UModal v-model:open="open" :title="t('reports.image_preview')" :description="image?.alt_text || undefined" :ui="{ content: 'max-w-5xl' }">
        <template #body>
            <img v-if="image" :src="image.url" :alt="image.alt_text" class="max-h-[65dvh] w-full object-contain" />
            <p v-if="image?.caption" class="mt-3 text-sm text-muted">{{ image.caption }}</p>
        </template>
        <template #footer>
            <div class="flex w-full flex-wrap items-center justify-between gap-3">
                <ReportButton v-if="image" :target="{ type: 'upload', id: image.id, label: image.alt_text || t('reports.types.upload') }" :entry-url="image.report_url" label-key="reports.report_image" />
                <UButton color="neutral" variant="outline" :label="t('reports.close')" @click="open = false" />
            </div>
        </template>
    </UModal>
</template>
