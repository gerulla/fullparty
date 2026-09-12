<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useResourceImages } from '@/composables/useResourceImages'
import { useResourceImageUpload } from '@/composables/useResourceImageUpload'
import type { ResourceImageSelection } from '@/Types/ResourceImages'
import ResourceImageGrid from './ResourceImageGrid.vue'
import ResourceImageUploadModal from './ResourceImageUploadModal.vue'

defineProps<{ selected?: string }>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ select: [image: ResourceImageSelection] }>()
const { t } = useI18n()
const images = useResourceImages()
const uploadImage = useResourceImageUpload()
const uploading = ref(false)
watch(open, value => { if (value) void images.load() })
function choose(image: ResourceImageSelection) { emit('select', image); open.value = false }
async function upload(file: File, alt?: string, caption?: string) {
    uploading.value = true
    try { return await uploadImage(file, alt, caption) }
    finally { uploading.value = false }
}
</script>

<template>
    <UModal v-model:open="open" :title="t('groups.resources.workspace.choose_image')" :dismissible="!uploading" :close="!uploading" :ui="{ content: 'max-w-3xl rounded-none', body: 'flex min-h-0 flex-col p-0 sm:p-0' }">
        <template #body>
            <ResourceImageGrid :images="images" :selected="selected?.split('/').pop()" class="h-[min(65dvh,640px)]" @select="choose">
                <template #actions><ResourceImageUploadModal :upload="upload" @uploaded="(_url, image) => choose(image)" /></template>
            </ResourceImageGrid>
        </template>
    </UModal>
</template>
