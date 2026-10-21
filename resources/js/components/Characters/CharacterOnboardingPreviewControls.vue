<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DevelopmentPreviewMenu from '@/components/Shared/DevelopmentPreviewMenu.vue'
import type { CharacterAddMethod, CharacterOnboardingPreviewScreen } from '../../Types/CharacterOnboarding'

const props = defineProps<{
    screen: CharacterOnboardingPreviewScreen
    method: CharacterAddMethod
    disabled: boolean
}>()
const emit = defineEmits<{
    preview: [screen: CharacterOnboardingPreviewScreen, method: CharacterAddMethod]
}>()
const { t } = useI18n()
const methods = computed(() => [
    { value: 'xivauth' as const, label: t('characters.onboarding.methods.xivauth.title') },
    { value: 'lodestone' as const, label: t('characters.onboarding.methods.lodestone.title') },
])
const screens = computed(() => {
    const values: CharacterOnboardingPreviewScreen[] = [
        'method', 'list_loading', 'list', 'selected',
        'loading', 'complete', 'transfer', 'transferred', 'partial', 'refresh_failed', 'empty', 'error',
        ...(props.method === 'xivauth' ? ['reconnect' as const] : []),
    ]
    return values.map(value => ({ value, label: t(value === 'transfer' ? 'characters.transfer.completion_title' : `characters.onboarding.preview.screens.${value}`) }))
})

function changeMethod(method: CharacterAddMethod) {
    const screen = ['live', 'selected', 'reconnect'].includes(props.screen) ? 'list' : props.screen
    emit('preview', screen, method)
}
</script>

<template>
    <DevelopmentPreviewMenu :active="screen !== 'live'" :disabled="disabled">
        <p class="px-2 text-xs leading-relaxed text-muted">{{ t('characters.onboarding.preview.help') }}</p>
        <UFormField :label="t('characters.onboarding.preview.method')" class="px-2 pt-2">
            <USelect :model-value="method" :items="methods" class="w-full rounded-md" :disabled="disabled"
                @update:model-value="changeMethod" />
        </UFormField>
        <UFormField :label="t('characters.onboarding.preview.screen')" class="px-2 pb-2">
            <USelect :model-value="screen === 'live' ? undefined : screen" :items="screens"
                :placeholder="t('characters.onboarding.preview.choose')" class="w-full rounded-md" :disabled="disabled"
                @update:model-value="emit('preview', $event, method)" />
        </UFormField>
        <UButton color="neutral" variant="soft" icon="i-lucide-undo-2" class="justify-start rounded-md"
            :disabled="screen === 'live' || disabled" @click="emit('preview', 'live', method)">
            {{ t('characters.onboarding.preview.exit') }}
        </UButton>
    </DevelopmentPreviewMenu>
</template>
