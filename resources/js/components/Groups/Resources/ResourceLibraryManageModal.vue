<script setup lang="ts">
import { computed, nextTick, provide, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceLibrary, ResourceLibrarySettings, ResourceLibrarySettingsErrors } from '@/Types/GroupResources'
import { resourceLibraryCustomization, resourceLibrarySettingsPayload } from '@/utils/resourceLibrarySettings'
import { resourceImageLibraryKey, useResourceImages } from '@/composables/useResourceImages'
import { resourceImageUploadKey } from '@/composables/useResourceImageUpload'
import ResourceLibraryCustomizationForm from './ResourceLibraryCustomizationForm.vue'

const props = withDefaults(defineProps<{
    open?: boolean
    groupSlug: string
    library: ResourceLibrary
    busy?: boolean
    error?: string
    errors?: ResourceLibrarySettingsErrors
}>(), { open: false, busy: false, error: '', errors: () => ({}) })

const emit = defineEmits<{
    close: [value?: boolean]
    save: [settings: ResourceLibrarySettings]
    delete: []
    clearErrors: []
    uploadsChanged: []
}>()

const { t } = useI18n()
const visibility = ref(props.library.visibility)
const customization = ref(resourceLibraryCustomization(props.library))
const form = ref<HTMLFormElement | null>(null)
const imageContext = { groupSlug: () => props.groupSlug, libraryOnly: true, changed: () => emit('uploadsChanged') }
provide(resourceImageLibraryKey, imageContext)
const images = useResourceImages(imageContext)
provide(resourceImageUploadKey, images.upload)
const blocked = computed(() => props.busy || images.state.busy)
watch([visibility, customization], () => emit('clearErrors'), { deep: true })
watch(() => props.errors, async errors => {
    await nextTick()
    const field = [...(form.value?.querySelectorAll<HTMLElement>('[data-library-field]') ?? [])].find(element => errors[element.dataset.libraryField ?? ''])
    field?.querySelector<HTMLElement>('input, textarea, button')?.focus()
    field?.scrollIntoView({ block: 'nearest' })
})
const options = computed(() => [
    { label: t('groups.resources.library.public'), value: 'public' },
    { label: t('groups.resources.library.group_only'), value: 'private' },
])
</script>

<template>
    <UModal
        :open="open"
        :title="t('groups.resources.library.title')"
        :description="t('groups.resources.library.description')"
        :dismissible="!blocked"
        :close="!blocked"
        :ui="{ content: visibility === 'public' ? 'max-w-2xl rounded-none' : 'max-w-lg rounded-none' }"
        @update:open="value => { if (!value && !blocked) emit('close', false) }"
    >
        <template #body>
            <form id="resource-library-settings" ref="form" @submit.prevent="!blocked && emit('save', resourceLibrarySettingsPayload(visibility, customization))">
                <fieldset :disabled="blocked" :inert="blocked" class="min-w-0 space-y-5">
                    <UAlert v-if="error" color="error" variant="soft" icon="i-lucide-circle-alert" :title="error" />
                    <UFormField name="visibility" data-library-field="visibility" :label="t('groups.resources.library.visibility')" :error="errors.visibility">
                        <URadioGroup v-model="visibility" :items="options" orientation="horizontal" />
                    </UFormField>
                    <template v-if="visibility === 'public'">
                        <USeparator />
                        <ResourceLibraryCustomizationForm v-model="customization" :errors="errors" />
                    </template>
                    <USeparator />
                    <div class="space-y-3">
                        <UAlert color="error" variant="subtle" icon="i-lucide-triangle-alert" :title="t('groups.resources.library.delete_warning')" />
                        <UButton
                            color="error"
                            variant="soft"
                            icon="i-lucide-trash-2"
                            :label="t('groups.resources.library.delete_all')"
                            :disabled="blocked"
                            @click="emit('delete')"
                        />
                    </div>
                </fieldset>
            </form>
        </template>
        <template #footer>
            <div class="flex w-full flex-wrap justify-end gap-2">
                <UButton color="neutral" variant="ghost" :label="t('general.cancel')" :disabled="blocked" @click="emit('close', false)" />
                <UButton type="submit" form="resource-library-settings" icon="i-lucide-save" :label="t('groups.resources.library.save')" :loading="busy" :disabled="images.state.busy" />
            </div>
        </template>
    </UModal>
</template>
