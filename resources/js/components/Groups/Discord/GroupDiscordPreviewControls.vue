<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import DevelopmentPreviewMenu from '@/components/Shared/DevelopmentPreviewMenu.vue'
import { useGroupDiscordPreview } from '@/composables/useGroupDiscordPreview'

const { t } = useI18n()
const { available, active } = useGroupDiscordPreview()
</script>

<template>
    <DevelopmentPreviewMenu v-if="available" :active="active">
        <div class="space-y-2 rounded-md bg-elevation-2 p-3 dark:bg-elevation-4">
            <USwitch v-model="active" color="brand" :label="t('groups.discord.preview.label')" />
            <p class="text-xs text-muted">{{ t('groups.discord.preview.description') }}</p>
        </div>
    </DevelopmentPreviewMenu>
</template>
