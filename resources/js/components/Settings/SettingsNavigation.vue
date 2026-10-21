<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SettingsNavigationGroup, SettingsSection } from '@/Types/Settings'

defineProps<{ activeSection: SettingsSection | null }>()
const emit = defineEmits<{ select: [section: SettingsSection] }>()
const { t } = useI18n()
const navigationElement = ref<HTMLElement | null>(null)

const groups: SettingsNavigationGroup[] = [
    { key: 'account', sections: [
        { key: 'general', icon: 'i-lucide-settings-2' },
        { key: 'socials', icon: 'i-lucide-users' },
    ] },
    { key: 'preferences', sections: [
        { key: 'notifications', icon: 'i-lucide-bell' },
        { key: 'privacy', icon: 'i-lucide-shield-check' },
    ] },
    { key: 'external', sections: [
        { key: 'discord', icon: 'ic:baseline-discord' },
        { key: 'plugin', icon: 'i-lucide-gamepad-2' },
    ] },
    { key: 'danger', sections: [
        { key: 'delete', icon: 'i-lucide-trash-2' },
    ] },
]

function focusSection(section: SettingsSection) {
    navigationElement.value?.querySelector<HTMLButtonElement>(`[data-settings-section="${section}"]`)?.focus()
}

defineExpose({ focusSection })
</script>

<template>
    <nav ref="navigationElement" :aria-label="t('settings.title')" class="space-y-6 xl:space-y-5">
        <div v-for="group in groups" :key="group.key" class="space-y-2">
            <h2 class="px-1 text-sm font-semibold text-muted">
                {{ t(`settings.navigation.groups.${group.key}`) }}
            </h2>
            <UButton
                v-for="section in group.sections"
                :key="section.key"
                :data-settings-section="section.key"
                :aria-current="activeSection === section.key ? 'true' : undefined"
                aria-controls="settings-section"
                :color="section.key === 'delete' ? 'error' : 'brand'"
                variant="ghost"
                class="w-full items-center gap-3 rounded-lg border p-4 text-left whitespace-normal transition-colors"
                :class="activeSection === section.key
                    ? (section.key === 'delete' ? 'border-error/50 bg-error/10' : 'border-brand/50 bg-brand/10')
                    : 'border-default bg-elevation-2 hover:bg-elevation-3'"
                @click="emit('select', section.key)"
            >
                <UIcon :name="section.icon" class="size-5 shrink-0"
                    :class="section.key === 'delete' ? 'text-error' : activeSection === section.key ? 'text-brand' : 'text-toned'" />
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold" :class="section.key === 'delete' ? 'text-error' : 'text-highlighted'">
                        {{ t(`settings.navigation.sections.${section.key}.title`) }}
                    </span>
                    <span class="mt-1 block text-xs leading-relaxed font-normal text-muted">
                        {{ t(`settings.navigation.sections.${section.key}.description`) }}
                    </span>
                </span>
                <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0 text-muted xl:hidden" />
            </UButton>
        </div>
    </nav>
</template>
