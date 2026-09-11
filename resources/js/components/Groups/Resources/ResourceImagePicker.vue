<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceImageSelection } from '@/Types/ResourceImages'
import ResourceImageLibraryModal from './ResourceImageLibraryModal.vue'

const props = defineProps<{ label: string; compact?: boolean; description?: string; error?: string; name?: string; valueType?: 'url' | 'uuid' }>()
const model = defineModel<string>({ required: true })
const { t } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const open = ref(false)
const chosenName = ref('')
const imageName = computed(() => model.value ? chosenName.value || l('selected_image') : l('choose_image'))
const imageUrl = computed(() => model.value && props.valueType === 'uuid' ? `/resource-assets/${model.value}` : model.value)
function show() { open.value = true }
function choose(image: ResourceImageSelection) { model.value = props.valueType === 'uuid' ? image.url.split('/').pop() ?? '' : image.url; chosenName.value = image.name }
</script>

<template>
    <UFormField :name="name" :error="error" :label="label" :description="description" :class="{ 'studio-form-row': compact, 'ring-1 ring-error p-1': error }">
        <div class="flex items-center gap-2">
            <div v-if="!compact" class="flex size-10 shrink-0 items-center justify-center border border-default bg-muted"><img v-if="model" :src="imageUrl" alt="" class="size-full object-contain" /><UIcon v-else name="i-lucide-image" class="size-4 text-dimmed" /></div>
            <UButton v-if="compact" color="neutral" variant="outline" size="sm" class="min-w-0 flex-1 justify-start" :aria-label="`${l('choose_image')}: ${label}`" @click="show">
                <img v-if="model" :src="imageUrl" alt="" class="size-6 shrink-0 object-cover" /><UIcon v-else name="i-lucide-image" class="size-4 shrink-0" /><span class="min-w-0 flex-1 truncate text-left">{{ imageName }}</span><UIcon name="i-lucide-chevron-down" class="size-4 shrink-0 text-muted" />
            </UButton>
            <UButton v-else icon="i-lucide-image-plus" color="neutral" variant="outline" size="sm" :label="l('choose_image')" @click="show" />
            <UTooltip v-if="model" :text="l('remove_image')"><UButton icon="i-lucide-x" color="neutral" variant="ghost" :aria-label="l('remove_image')" @click="model = ''; chosenName = ''" /></UTooltip>
        </div>
        <ResourceImageLibraryModal v-model:open="open" :selected="imageUrl" @select="choose" />
    </UFormField>
</template>
