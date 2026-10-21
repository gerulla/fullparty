<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AuthPreviewScreen } from '../../Types/AuthPreview'

defineProps<{ modelValue: AuthPreviewScreen; standalone?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [screen: AuthPreviewScreen] }>()
const { t } = useI18n()
const open = ref(false)
const screens: AuthPreviewScreen[] = [
    'live', 'reset_password', 'verify_email', 'verify_email_resent',
    'link_social', 'link_social_verification', 'link_social_ready', 'link_social_expired',
    'plugin_code', 'plugin_invalid', 'plugin_authorize', 'plugin_approved', 'plugin_denied', 'banned',
]
const items = computed(() => screens.map(value => ({ value, label: t(`auth.preview.screens.${value}`) })))
</script>

<template>
    <UPopover v-model:open="open" :content="{ side: standalone ? 'top' : 'bottom', align: 'start', collisionPadding: 8 }"
        :ui="{ content: 'max-w-[calc(100vw-2rem)] rounded-md bg-elevation-1 text-default ring-default shadow-lg dark:bg-elevation-3' }">
        <UButton type="button" color="neutral" variant="ghost" size="sm" square
            :icon="open ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            class="relative rounded-md bg-neutral-950/60 text-white hover:bg-neutral-900/85"
            :aria-label="t('auth.preview.title')" :title="t('auth.preview.title')">
            <span v-if="modelValue !== 'live'" class="absolute right-1 top-1 size-1.5 rounded-full bg-brand-400" aria-hidden="true" />
        </UButton>
        <template #content>
            <div class="w-80 max-w-full space-y-3 p-4">
                <p class="text-sm font-semibold">{{ t('auth.preview.title') }}</p>
                <UFormField :label="t('auth.preview.screen')">
                    <USelect :model-value="modelValue" :items="items" class="w-full rounded-md"
                        @update:model-value="emit('update:modelValue', $event)" />
                </UFormField>
                <p class="text-xs leading-relaxed text-muted">{{ t('auth.preview.notice') }}</p>
            </div>
        </template>
    </UPopover>
</template>
