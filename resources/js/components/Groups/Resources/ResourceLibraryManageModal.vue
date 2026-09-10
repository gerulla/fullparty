<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceLibraryVisibility } from '@/Types/GroupResources'

const props = withDefaults(defineProps<{
    open?: boolean
    visibility: ResourceLibraryVisibility
    busy?: boolean
    error?: string
}>(), { open: false, busy: false, error: '' })

const emit = defineEmits<{
    close: [value?: boolean]
    save: [visibility: ResourceLibraryVisibility]
    delete: []
}>()

const { t } = useI18n()
const visibility = ref<ResourceLibraryVisibility>(props.visibility)
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
        :dismissible="!busy"
        :close="!busy"
        @update:open="value => { if (!value && !busy) emit('close', false) }"
    >
        <template #body>
            <form id="resource-library-settings" class="space-y-5" @submit.prevent="!busy && emit('save', visibility)">
                <UFormField :label="t('groups.resources.library.visibility')" :error="error || undefined">
                    <URadioGroup v-model="visibility" :items="options" orientation="horizontal" :disabled="busy" />
                </UFormField>
                <USeparator />
                <div class="space-y-3">
                    <UAlert color="error" variant="subtle" icon="i-lucide-triangle-alert" :title="t('groups.resources.library.delete_warning')" />
                    <UButton
                        color="error"
                        variant="soft"
                        icon="i-lucide-trash-2"
                        :label="t('groups.resources.library.delete_all')"
                        :disabled="busy"
                        @click="emit('delete')"
                    />
                </div>
            </form>
        </template>
        <template #footer>
            <div class="flex w-full flex-wrap justify-end gap-2">
                <UButton color="neutral" variant="ghost" :label="t('general.cancel')" :disabled="busy" @click="emit('close', false)" />
                <UButton type="submit" form="resource-library-settings" icon="i-lucide-save" :label="t('groups.resources.library.save')" :loading="busy" />
            </div>
        </template>
    </UModal>
</template>
