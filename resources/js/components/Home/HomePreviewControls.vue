<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
    refreshing: boolean
    emptyAccount: boolean
}>()
defineEmits<{
    refresh: []
    toggleEmpty: []
}>()
const { t } = useI18n()
</script>

<template>
    <div class="fixed bottom-4 left-4 z-40 flex max-w-[calc(100vw-2rem)] flex-wrap items-center gap-2 rounded-lg border border-default bg-elevation-1 p-2 shadow-lg dark:bg-elevation-3"
        role="group" :aria-label="t('dashboard.preview.title')">
        <UButton type="button" color="neutral" variant="soft" size="sm" icon="i-lucide-refresh-cw"
            :loading="refreshing" :disabled="refreshing" class="rounded-md"
            :title="t('dashboard.preview.refresh_help')" @click="$emit('refresh')">
            {{ t('dashboard.preview.refresh') }}
        </UButton>
        <UButton type="button" color="brand" :variant="emptyAccount ? 'solid' : 'soft'" size="sm"
            :icon="emptyAccount ? 'i-lucide-undo-2' : 'i-lucide-flask-conical'"
            :disabled="refreshing" :aria-pressed="emptyAccount" class="rounded-md"
            :title="t('dashboard.preview.empty_help')" @click="$emit('toggleEmpty')">
            {{ t(`dashboard.preview.${emptyAccount ? 'restore' : 'empty'}`) }}
        </UButton>
    </div>
</template>
