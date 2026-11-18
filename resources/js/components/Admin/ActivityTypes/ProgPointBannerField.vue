<script setup lang="ts">
import { useObjectUrl } from '@vueuse/core'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
    file?: File | null
    imageUrl?: string | null
    error?: string
}>()

const emit = defineEmits<{
    select: [file: File]
    remove: []
}>()

const { t } = useI18n()
const fileUrl = useObjectUrl(() => props.file)
const preview = computed(() => fileUrl.value || props.imageUrl)
</script>

<template>
    <UFormField
        :label="t('admin.activity_types.general.banner_image.label')"
        :help="t('admin.activity_types.prog_points.banner_help')"
        :error="error"
    >
        <div class="space-y-2">
            <img
                v-if="preview"
                :src="preview"
                :alt="t('admin.activity_types.prog_points.banner_preview')"
                class="aspect-[3/1] w-full max-w-md rounded-md bg-elevated object-contain"
            >
            <div class="flex flex-wrap items-center gap-2">
                <UFileUpload
                    :model-value="file"
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    :preview="false"
                    :dropzone="false"
                    reset
                    @update:model-value="value => value && emit('select', value)"
                >
                    <template #default="{ open }">
                        <UButton
                            type="button"
                            color="neutral"
                            variant="outline"
                            icon="i-lucide-image-up"
                            :label="t('admin.activity_types.general.banner_image.placeholder')"
                            @click="open()"
                        />
                    </template>
                </UFileUpload>
                <UButton
                    v-if="preview"
                    type="button"
                    color="neutral"
                    variant="ghost"
                    icon="i-lucide-x"
                    :label="t('admin.activity_types.prog_points.remove_banner')"
                    @click="emit('remove')"
                />
            </div>
        </div>
    </UFormField>
</template>
