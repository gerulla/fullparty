<script setup lang="ts">
import axios from 'axios'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceImageSelection, ResourceImageUpload } from '@/Types/ResourceImages'

const props = defineProps<{ upload: ResourceImageUpload }>()
const emit = defineEmits<{ uploaded: [url: string, image: ResourceImageSelection] }>()
const { t } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const fileInput = ref<HTMLInputElement | null>(null)
const file = ref<File | null>(null)
const open = ref(false)
const loading = ref(false)
const error = ref('')
const alt = ref('')
const caption = ref('')
function choose(event: Event) {
    const input = event.target as HTMLInputElement
    file.value = input.files?.[0] ?? null; input.value = ''
    if (!file.value) return
    error.value = ''; alt.value = ''; caption.value = ''; open.value = true
}
async function submit() {
    if (!file.value || loading.value) return
    loading.value = true; error.value = ''
    try {
        const url = await props.upload(file.value, alt.value, caption.value)
        open.value = false
        emit('uploaded', url, { url, name: file.value.name, alt_text: alt.value, caption: caption.value })
    }
    catch (failure) {
        const errors = axios.isAxiosError(failure) ? failure.response?.data?.errors : null
        error.value = errors ? Object.values(errors).flat().join(' ') : l('image_invalid')
    } finally { loading.value = false }
}
</script>

<template>
    <UButton icon="i-lucide-upload" color="neutral" :label="l('upload_image')" :disabled="loading" @click="fileInput?.click()" />
    <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" @change="choose" />
    <UModal v-model:open="open" :title="l('upload_image')" :description="file?.name" :dismissible="!loading" :close="!loading" :ui="{ content: 'rounded-none' }">
        <template #body>
            <form class="space-y-4" @submit.prevent="submit">
                <UAlert v-if="error" :title="error" color="error" variant="soft" />
                <p class="text-sm text-muted">{{ l('image_invalid') }}</p>
                <UFormField :label="l('alt_text')"><UInput v-model="alt" :maxlength="500" :disabled="loading" class="w-full" /></UFormField>
                <UFormField :label="l('caption')"><UTextarea v-model="caption" :maxlength="1000" :disabled="loading" class="w-full" /></UFormField>
                <div class="flex justify-end gap-2"><UButton color="neutral" variant="outline" :label="l('cancel')" :disabled="loading" @click="open = false" /><UButton icon="i-lucide-upload" type="submit" :label="l('upload_image')" :loading="loading" /></div>
            </form>
        </template>
    </UModal>
</template>
