<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import DevelopmentPreviewMenu from '@/components/Shared/DevelopmentPreviewMenu.vue'
import { useAccountFlagsPreview } from '@/composables/useAccountFlagsPreview'

defineProps<{
    refreshing: boolean
    emptyAccount: boolean
}>()
defineEmits<{
    refresh: []
    toggleEmpty: []
}>()
const { t } = useI18n()
const { enabled: previewFlags, toggle: toggleFlags } = useAccountFlagsPreview()
</script>

<template>
    <DevelopmentPreviewMenu :active="emptyAccount || previewFlags" :loading="refreshing" v-slot="{ close }">
        <UButton type="button" color="neutral" variant="soft" size="sm" icon="i-lucide-refresh-cw"
            :loading="refreshing" :disabled="refreshing" class="w-full justify-start whitespace-normal rounded-md text-left"
            :title="t('dashboard.preview.refresh_help')" @click="$emit('refresh'); close()">
            {{ t('dashboard.preview.refresh') }}
        </UButton>
        <UButton type="button" color="brand" :variant="emptyAccount ? 'solid' : 'soft'" size="sm"
            :icon="emptyAccount ? 'i-lucide-undo-2' : 'i-lucide-flask-conical'"
            :disabled="refreshing" :aria-pressed="emptyAccount" class="w-full justify-start whitespace-normal rounded-md text-left"
            :title="t('dashboard.preview.empty_help')" @click="$emit('toggleEmpty'); close()">
            {{ t(`dashboard.preview.${emptyAccount ? 'restore' : 'empty'}`) }}
        </UButton>
        <UButton type="button" color="neutral" :variant="previewFlags ? 'solid' : 'soft'" size="sm" icon="i-lucide-flag"
            :aria-pressed="previewFlags" class="w-full justify-start whitespace-normal rounded-md text-left"
            @click="toggleFlags(); close()">
            {{ t(`general.account_flags.${previewFlags ? 'end_preview' : 'preview'}`) }}
        </UButton>
    </DevelopmentPreviewMenu>
</template>
