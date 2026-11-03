<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import DevelopmentPreviewMenu from '@/components/Shared/DevelopmentPreviewMenu.vue'
import type { GroupDashboardView } from '@/Types/Groups'

defineProps<{ view: GroupDashboardView; active: boolean }>()
const emit = defineEmits<{ 'update:view': [view: GroupDashboardView] }>()
const { t } = useI18n()
</script>

<template>
    <DevelopmentPreviewMenu :active="active">
        <div class="space-y-2 rounded-md bg-elevation-2 p-2 dark:bg-elevation-4">
            <p class="text-xs font-medium text-muted">{{ t('groups.index.dashboard_preview.view') }}</p>
            <div class="grid grid-cols-2 gap-2" role="group" :aria-label="t('groups.index.dashboard_preview.view')">
                <UButton v-for="option in (['user', 'staff'] as const)" :key="option" type="button" color="neutral"
                    :variant="view === option ? 'solid' : 'ghost'" :aria-pressed="view === option"
                    class="justify-center rounded-md" @click="emit('update:view', option)">
                    {{ t(`groups.index.dashboard_preview.${option}`) }}
                </UButton>
            </div>
        </div>
    </DevelopmentPreviewMenu>
</template>
