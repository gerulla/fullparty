<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceLibraryCustomization, ResourceLibrarySettingsErrors } from '@/Types/GroupResources'
import ResourceImagePicker from './ResourceImagePicker.vue'

const props = defineProps<{ errors: ResourceLibrarySettingsErrors }>()
const model = defineModel<ResourceLibraryCustomization>({ required: true })
const { t } = useI18n()
const l = (key: string) => t(`groups.resources.library.customization.${key}`)
const error = (field: string) => props.errors[`customization.${field}`]
const appearances = computed(() => ['light', 'dark', 'system'].map(value => ({ value, label: l(value) })))
const color = computed(() => /^#[a-f0-9]{6}$/i.test(model.value.accent_color) ? model.value.accent_color : '#8457b0')
</script>

<template>
    <section class="min-w-0 space-y-5">
        <h3 class="text-sm font-semibold">{{ l('heading') }}</h3>
        <UFormField name="customization.title" data-library-field="customization.title" :label="l('title')" :error="error('title')" required>
            <UInput v-model="model.title" :maxlength="160" class="w-full" />
        </UFormField>
        <UFormField name="customization.introduction" data-library-field="customization.introduction" :label="l('introduction')" :error="error('introduction')" required>
            <UTextarea v-model="model.introduction" :maxlength="2000" :rows="3" class="w-full" />
        </UFormField>
        <ResourceImagePicker :model-value="model.banner_image_id ?? ''" value-type="uuid" :label="l('banner')" name="customization.banner_image_id" data-library-field="customization.banner_image_id" :error="error('banner_image_id')" @update:model-value="model.banner_image_id = $event || null" />
        <div v-if="model.banner_image_id" class="space-y-4">
            <div class="aspect-[3/1] overflow-hidden border border-default bg-muted">
                <img :src="`/resource-assets/${model.banner_image_id}`" alt="" class="h-full w-full object-cover" :style="{ objectPosition: `${model.banner_focal_x}% ${model.banner_focal_y}%` }" />
            </div>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <UFormField name="customization.banner_focal_x" data-library-field="customization.banner_focal_x" :label="l('focal_x')" :error="error('banner_focal_x')" :hint="`${model.banner_focal_x}%`">
                    <USlider v-model="model.banner_focal_x" :min="0" :max="100" :step="1" :aria-label="l('focal_x')" />
                </UFormField>
                <UFormField name="customization.banner_focal_y" data-library-field="customization.banner_focal_y" :label="l('focal_y')" :error="error('banner_focal_y')" :hint="`${model.banner_focal_y}%`">
                    <USlider v-model="model.banner_focal_y" :min="0" :max="100" :step="1" :aria-label="l('focal_y')" />
                </UFormField>
            </div>
        </div>
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <ResourceImagePicker :model-value="model.logo_image_id ?? ''" value-type="uuid" :label="l('logo')" name="customization.logo_image_id" data-library-field="customization.logo_image_id" :error="error('logo_image_id')" @update:model-value="model.logo_image_id = $event || null" />
            <ResourceImagePicker :model-value="model.sharing_image_id ?? ''" value-type="uuid" :label="l('sharing_image')" name="customization.sharing_image_id" data-library-field="customization.sharing_image_id" :error="error('sharing_image_id')" @update:model-value="model.sharing_image_id = $event || null" />
            <UFormField name="customization.accent_color" data-library-field="customization.accent_color" :label="l('accent_color')" :error="error('accent_color')">
                <div class="flex gap-2">
                    <UPopover :ui="{ content: 'rounded-none bg-elevated p-3 ring ring-default' }">
                        <UButton color="neutral" variant="outline" class="size-8 shrink-0 justify-center p-1" :aria-label="l('accent_color')"><span class="size-5 border border-default" :style="{ backgroundColor: color }" /></UButton>
                        <template #content><UColorPicker :model-value="color" format="hex" :aria-label="l('accent_color')" @update:model-value="model.accent_color = $event ?? '#8457b0'" /></template>
                    </UPopover>
                    <UInput v-model="model.accent_color" :maxlength="7" class="min-w-0 flex-1" />
                </div>
            </UFormField>
            <UFormField name="customization.appearance" data-library-field="customization.appearance" :label="l('appearance')" :error="error('appearance')">
                <USelect v-model="model.appearance" :items="appearances" class="w-full" />
            </UFormField>
        </div>
        <USeparator />
        <div class="space-y-3" data-library-field="customization.links">
            <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-semibold">{{ l('links') }}</h3><span class="text-xs text-muted">{{ model.links.length }}/8</span></div>
            <p v-if="error('links')" class="text-sm text-error">{{ error('links') }}</p>
            <div v-for="(link, index) in model.links" :key="index" class="flex items-start gap-2">
                <div class="grid min-w-0 flex-1 grid-cols-1 gap-2 sm:grid-cols-[1fr_2fr]">
                    <UFormField :name="`customization.links.${index}.label`" :data-library-field="`customization.links.${index}.label`" :label="l('link_label')" :error="error(`links.${index}.label`)" required>
                        <UInput v-model="link.label" :maxlength="60" class="w-full" />
                    </UFormField>
                    <UFormField :name="`customization.links.${index}.url`" :data-library-field="`customization.links.${index}.url`" :label="l('link_url')" :error="error(`links.${index}.url`)" required>
                        <UInput v-model="link.url" :maxlength="2048" placeholder="https://" class="w-full" />
                    </UFormField>
                </div>
                <UTooltip :text="l('remove_link')"><UButton icon="i-lucide-trash-2" color="error" variant="soft" class="mt-6 shrink-0" :aria-label="l('remove_link')" @click="model.links.splice(index, 1)" /></UTooltip>
            </div>
            <UButton icon="i-lucide-plus" color="neutral" variant="outline" size="sm" :label="l('add_link')" :disabled="model.links.length >= 8" @click="model.links.push({ label: '', url: '' })" />
        </div>
    </section>
</template>
