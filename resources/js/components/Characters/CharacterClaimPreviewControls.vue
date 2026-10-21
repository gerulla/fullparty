<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DevelopmentPreviewMenu from '@/components/Shared/DevelopmentPreviewMenu.vue'
import { characterClaimPreviewScreens } from '@/utils/characterClaimPreview'
import type { CharacterClaimPreviewScreen } from '../../Types/CharacterClaim'

defineProps<{ screen: CharacterClaimPreviewScreen; disabled: boolean }>()
const emit = defineEmits<{ preview: [screen: CharacterClaimPreviewScreen] }>()
const { t } = useI18n()
const screens = computed(() => characterClaimPreviewScreens.map(value => ({
    value, label: t(value === 'transfer' ? 'characters.transfer.completion_title' : `characters.claim_flow.preview.screens.${value}`),
})))
</script>

<template>
    <DevelopmentPreviewMenu :active="screen !== 'live'" :disabled="disabled">
        <p class="px-2 text-xs leading-relaxed text-muted">{{ t('characters.claim_flow.preview.help') }}</p>
        <UFormField :label="t('characters.onboarding.preview.screen')" class="px-2 py-2">
            <USelect :model-value="screen === 'live' ? undefined : screen" :items="screens"
                :placeholder="t('characters.onboarding.preview.choose')" class="w-full rounded-md" :disabled="disabled"
                @update:model-value="emit('preview', $event)" />
        </UFormField>
        <UButton color="neutral" variant="soft" icon="i-lucide-undo-2" class="justify-start rounded-md"
            :disabled="screen === 'live' || disabled" @click="emit('preview', 'live')">
            {{ t('characters.claim_flow.preview.exit') }}
        </UButton>
    </DevelopmentPreviewMenu>
</template>
