<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useUiTheme } from '@/composables/useUiTheme'
import { useDevelopmentPreviewMenu } from '@/composables/useDevelopmentPreviewMenu'

const { t } = useI18n()
const { theme, previewTheme } = useUiTheme()
const { open, controls } = useDevelopmentPreviewMenu()
</script>

<template>
    <UPopover v-model:open="open" :content="{ side: 'bottom', align: 'start', collisionPadding: 8 }"
        :ui="{ content: 'max-w-[calc(100vw-2rem)] rounded-md bg-elevation-1 text-default ring-default shadow-lg dark:bg-elevation-3' }">
        <UButton type="button" color="neutral" variant="ghost" size="sm" square
            :icon="open ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" :loading="controls?.loading" :disabled="controls?.disabled"
            class="relative rounded-md hover:bg-elevated focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
            :class="{ 'bg-elevated': open }"
            :aria-label="t('dashboard.preview.title')" :title="t('dashboard.preview.title')">
            <span v-if="controls?.active" class="absolute right-1 top-1 size-1.5 rounded-full bg-primary" aria-hidden="true" />
        </UButton>
        <template #content>
            <div class="flex w-72 max-w-full flex-col gap-2 p-2" role="group" :aria-label="t('dashboard.preview.title')">
                <p class="px-2 py-1 text-xs font-medium text-muted">{{ t('dashboard.preview.title') }}</p>
                <div class="space-y-2 rounded-md bg-elevation-2 p-2 dark:bg-elevation-4">
                    <p class="text-xs font-medium text-muted">{{ t('navigation.topbar.appearance.theme') }}</p>
                    <div class="grid grid-cols-2 gap-2" role="group" :aria-label="t('navigation.topbar.appearance.theme')">
                        <UButton color="neutral" :variant="theme === 'classic' ? 'solid' : 'ghost'" :aria-pressed="theme === 'classic'"
                            class="justify-center rounded-md" @click="previewTheme('classic')">
                            {{ t('navigation.topbar.appearance.base') }}
                        </UButton>
                        <UButton color="brand" :variant="theme === 'medas' ? 'solid' : 'ghost'" :aria-pressed="theme === 'medas'"
                            class="justify-center rounded-md" @click="previewTheme('medas')">
                            {{ t('navigation.topbar.appearance.medas') }}
                        </UButton>
                    </div>
                </div>
                <div id="v2-preview-content" class="flex flex-col gap-2" />
            </div>
        </template>
    </UPopover>
</template>
