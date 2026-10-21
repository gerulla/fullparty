<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAppearance } from '@/composables/useAppearance'

const { t } = useI18n()
const { isDark, toggleAppearance } = useAppearance()
const label = computed(() => t(`navigation.topbar.appearance.${isDark.value ? 'light' : 'dark'}`))
</script>

<template>
    <UTooltip :text="label">
        <UButton
            type="button"
            color="neutral"
            variant="ghost"
            class="rounded-md hover:bg-elevated"
            :icon="isDark ? 'i-lucide-moon' : 'i-lucide-sun'"
            :aria-label="label"
            @click="toggleAppearance"
        />
    </UTooltip>
</template>
