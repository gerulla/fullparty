<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DropdownMenuItem } from '@nuxt/ui'
import { useAppearance } from '@/composables/useAppearance'
import { useUiTheme } from '@/composables/useUiTheme'

const { t } = useI18n()
const { isDark, setAppearance, toggleAppearance } = useAppearance()
const { theme, canPreviewTheme, previewTheme } = useUiTheme()
const label = computed(() => t(`navigation.topbar.appearance.${isDark.value ? 'light' : 'dark'}`))

const previewItems = computed<DropdownMenuItem[][]>(() => [
    [
        { type: 'label', label: t('navigation.topbar.appearance.mode') },
        {
            type: 'checkbox', label: t('navigation.topbar.appearance.dark_label'),
            icon: 'i-lucide-moon', checked: isDark.value,
            onSelect(event) { event.preventDefault(); setAppearance('dark') },
        },
        {
            type: 'checkbox', label: t('navigation.topbar.appearance.light_label'),
            icon: 'i-lucide-sun', checked: !isDark.value,
            onSelect(event) { event.preventDefault(); setAppearance('light') },
        },
    ],
    [
        { type: 'label', label: t('navigation.topbar.appearance.theme') },
        {
            type: 'checkbox', label: t('navigation.topbar.appearance.base'),
            checked: theme.value === 'classic',
            onSelect(event) { event.preventDefault(); previewTheme('classic') },
        },
        {
            type: 'checkbox', label: t('navigation.topbar.appearance.medas'),
            checked: theme.value === 'medas',
            onSelect(event) { event.preventDefault(); previewTheme('medas') },
        },
    ],
])
</script>

<template>
    <UDropdownMenu class="rounded-sm" v-if="canPreviewTheme" :items="previewItems" :content="{ align: 'end' }">
        <UButton
            type="button"
            color="neutral"
            variant="ghost"
            :icon="isDark ? 'i-lucide-moon' : 'i-lucide-sun'"
            trailing-icon="i-lucide-chevron-down"
            :aria-label="t('navigation.topbar.appearance.preview')"
        />
    </UDropdownMenu>
    <UTooltip v-else :text="label">
        <UButton
            type="button"
            color="neutral"
            variant="ghost"
            :icon="isDark ? 'i-lucide-moon' : 'i-lucide-sun'"
            :aria-label="label"
            @click="toggleAppearance"
        />
    </UTooltip>
</template>
