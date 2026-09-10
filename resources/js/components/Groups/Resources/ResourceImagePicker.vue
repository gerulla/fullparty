<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { workspaceImages } from '@/utils/mockResourceData'
import { readWorkspaceImage } from '@/utils/resourceWorkspaceImage'

defineProps<{ label: string; compact?: boolean }>()
const model = defineModel<string>({ required: true })
const { t } = useI18n()
const fileInput = ref<HTMLInputElement | null>(null)
const error = ref('')
const loading = ref(false)
const open = ref(false)
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const imageName = computed(() => {
    if (!model.value) return l('choose_image')
    if (model.value.startsWith('data:')) return l('selected_image')
    return model.value.split('/').pop() || l('selected_image')
})
async function upload(event: Event) {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    input.value = ''
    if (!file) return
    error.value = ''
    loading.value = true
    try {
        model.value = await readWorkspaceImage(file)
        open.value = false
    } catch { error.value = l('image_invalid') }
    finally { loading.value = false }
}
</script>

<template>
    <UFormField :label="label" :error="error || undefined" :class="{ 'studio-form-row': compact }">
        <div class="flex items-center gap-2">
            <div v-if="!compact" class="flex size-10 shrink-0 items-center justify-center border border-default bg-muted">
                <img v-if="model" :src="model" alt="" class="size-full object-contain" /><UIcon v-else name="i-lucide-image" class="size-4 text-dimmed" />
            </div>
            <UPopover v-model:open="open">
                <UButton v-if="compact" color="neutral" variant="outline" size="sm" class="min-w-0 flex-1 justify-start" :aria-label="`${l('choose_image')}: ${label}`">
                    <img v-if="model" :src="model" alt="" class="size-6 shrink-0 object-cover" /><UIcon v-else name="i-lucide-image" class="size-4 shrink-0" /><span class="min-w-0 flex-1 truncate text-left">{{ imageName }}</span><UIcon name="i-lucide-chevron-down" class="size-4 shrink-0 text-muted" />
                </UButton>
                <UButton v-else icon="i-lucide-image-plus" color="neutral" variant="outline" size="sm" :label="l('choose_image')" />
                <template #content>
                    <div class="w-64 space-y-3 p-3">
                        <div class="grid grid-cols-4 gap-2">
                            <button v-for="(image, index) in workspaceImages" :key="image" class="flex aspect-square items-center justify-center border border-default hover:border-primary" :aria-label="`${l('choose_image')} ${index + 1}`" @click="model = image; open = false"><img :src="image" alt="" class="size-full object-contain" /></button>
                        </div>
                        <UButton block icon="i-lucide-upload" color="neutral" variant="soft" :label="l('upload_image')" :loading="loading" @click="fileInput?.click()" />
                    </div>
                </template>
            </UPopover>
            <UTooltip v-if="model" :text="l('remove_image')"><UButton icon="i-lucide-x" color="neutral" variant="ghost" :aria-label="l('remove_image')" @click="model = ''" /></UTooltip>
            <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" @change="upload" />
        </div>
    </UFormField>
</template>
