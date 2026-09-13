<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceImagesController } from '@/Types/ResourceImages'
import { formatBytes } from '@/utils/formatBytes'
import ConfirmationModal from '@/components/Shared/Modals/ConfirmationModal.vue'
import ReportButton from '@/components/Shared/Reports/ReportButton.vue'
const props = defineProps<{ images: ResourceImagesController }>()
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.uploads.${key}`)
const w = (key: string) => t(`groups.resources.workspace.${key}`)
const image = computed(() => props.images.selected.value)
const form = reactive({ name: '', alt_text: '', caption: '' })
const deleting = ref(false)
watch(image, value => { form.name = value?.name ?? ''; form.alt_text = value?.alt_text ?? ''; form.caption = value?.caption ?? '' }, { immediate: true })
const dirty = computed(() => image.value && (form.name !== image.value.name || form.alt_text !== image.value.alt_text || form.caption !== (image.value.caption ?? '')))
async function remove() { const success = await props.images.remove(); if (success) deleting.value = false; return success }
</script>

<template>
    <aside class="min-w-0 p-4">
        <form v-if="image" class="space-y-5" @submit.prevent="images.save(image, form)">
            <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-base font-semibold">{{ l('details') }}</h2><ReportButton :target="{ type: 'upload', id: image.id, label: image.name }" label-key="reports.report_image" /></div>
            <a :href="image.url" target="_blank" rel="noopener noreferrer" class="flex aspect-[4/3] items-center justify-center overflow-hidden bg-muted" :aria-label="w('preview')"><img :src="image.url" :alt="image.alt_text" class="size-full object-contain" /></a>
            <div class="flex flex-wrap items-center gap-2"><UBadge color="neutral" variant="soft">{{ image.mime_type.split('/').pop()?.toUpperCase() }}</UBadge><UBadge :color="image.in_use ? 'info' : 'neutral'" variant="soft">{{ image.in_use ? l('in_use') : l('unused') }}</UBadge></div>
            <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm"><dt class="text-muted">{{ l('dimensions') }}</dt><dd>{{ image.width }} &times; {{ image.height }}</dd><dt class="text-muted">{{ l('size') }}</dt><dd>{{ formatBytes(image.size_bytes, locale) }}</dd><dt class="text-muted">{{ l('uploaded_by') }}</dt><dd class="break-words">{{ image.uploader || w('none') }}</dd><dt class="text-muted">{{ l('uploaded_at') }}</dt><dd>{{ new Date(image.created_at).toLocaleDateString(locale) }}</dd></dl>
            <UFormField :label="l('filename')" required><UInput v-model="form.name" :maxlength="255" required :disabled="images.state.busy" class="w-full" /></UFormField>
            <UFormField :label="w('alt_text')"><UTextarea v-model="form.alt_text" :maxlength="500" :disabled="images.state.busy" class="w-full" /></UFormField>
            <UFormField :label="w('caption')"><UTextarea v-model="form.caption" :maxlength="1000" :disabled="images.state.busy" class="w-full" /></UFormField>
            <div class="flex flex-wrap gap-2"><UButton type="submit" color="neutral" icon="i-lucide-check" :label="w('save')" :disabled="!dirty || !form.name.trim()" :loading="images.state.busy" /><UButton color="error" variant="soft" icon="i-lucide-trash-2" :label="w('delete')" :disabled="image.in_use || images.state.busy" @click="images.state.error = ''; deleting = true" /></div>
            <p v-if="image.in_use" class="text-sm text-muted">{{ l('protected') }}</p>
        </form>
        <div v-else class="flex min-h-48 h-full flex-col items-center justify-center gap-3 text-center text-muted"><UIcon name="i-lucide-image" class="size-8" /><p>{{ l('select') }}</p></div>
        <ConfirmationModal v-model:open="deleting" :title="l('delete_title')" :description="images.state.error" :warning-text="l('delete_description')" :confirm-label="w('delete')" severity="error" :confirm-loading="images.state.busy" :on-confirm="remove" @close="deleting = false" />
    </aside>
</template>
