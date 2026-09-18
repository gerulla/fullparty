<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useResourceImages } from '@/composables/useResourceImages'
import { useResourceImageUpload } from '@/composables/useResourceImageUpload'
import type { ResourceImageSelection } from '@/Types/ResourceImages'
import type { GameIcon } from '@/Types/GameIcons'
import { safeGameIconSource } from '@/utils/gameIcons'
import GameIconGrid from '@/components/Shared/GameIconGrid.vue'
import ResourceImageGrid from './ResourceImageGrid.vue'
import ResourceImageUploadModal from './ResourceImageUploadModal.vue'

const props = defineProps<{ selected?: string; allowGameIcons?: boolean }>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ select: [image: ResourceImageSelection] }>()
const { t, locale } = useI18n()
const images = useResourceImages()
const uploadImage = useResourceImageUpload()
const uploading = ref(false)
const source = ref('uploads')
const sources = computed(() => [
    { value: 'uploads', label: t('groups.resources.uploads.title'), icon: 'i-lucide-images' },
    { value: 'game-icons', label: t('rich_text.game_icons'), icon: 'i-lucide-smile' },
])
const selectedIcon = computed(() => {
    if (!props.allowGameIcons || !props.selected) return undefined
    try {
        const url = new URL(props.selected, window.location.origin)
        return url.origin === window.location.origin ? safeGameIconSource(url.pathname) ?? undefined : undefined
    } catch { return undefined }
})
watch(open, value => {
    if (!value) return
    source.value = selectedIcon.value ? 'game-icons' : 'uploads'
})
watch([open, source], ([isOpen, value]) => { if (isOpen && value === 'uploads') void images.load() })
function choose(image: ResourceImageSelection) { emit('select', image); open.value = false }
function chooseIcon(icon: GameIcon) {
    const name = icon.names[locale.value] || icon.names.en
    choose({ url: new URL(icon.src, window.location.origin).href, name, alt_text: name, caption: null })
}
async function upload(file: File, alt?: string, caption?: string) {
    uploading.value = true
    try { return await uploadImage(file, alt, caption) }
    finally { uploading.value = false }
}
</script>

<template>
    <UModal v-model:open="open" :title="t('groups.resources.workspace.choose_image')" :dismissible="!uploading" :close="!uploading" :ui="{ content: 'max-w-3xl rounded-none', body: 'flex min-h-0 flex-col p-0 sm:p-0' }">
        <template #body>
            <UTabs v-if="allowGameIcons" v-model="source" :items="sources" :content="false" variant="link" class="shrink-0 border-b border-default" :ui="{ list: 'w-full rounded-none px-3', trigger: 'flex-1 rounded-none py-3' }" />
            <div class="flex h-[min(65dvh,640px)] min-h-0 flex-col">
                <GameIconGrid v-if="allowGameIcons && source === 'game-icons'" :selected="selectedIcon" @select="chooseIcon" />
                <ResourceImageGrid v-else :images="images" :selected="selected?.split('/').pop()" @select="choose">
                    <template #actions><ResourceImageUploadModal :upload="upload" @uploaded="(_url, image) => choose(image)" /></template>
                </ResourceImageGrid>
            </div>
        </template>
    </UModal>
</template>
