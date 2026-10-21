<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
    name: string
    iconUrl?: string | null
    level: string
    locked: boolean
    preferred: boolean
    loading: boolean
    disabled: boolean
}>()
const emit = defineEmits<{ toggle: [] }>()
const { t } = useI18n()
</script>

<template>
    <div class="flex min-w-0 items-center gap-3 rounded-lg border bg-elevation-2 p-3 transition-colors dark:bg-elevation-5"
        :class="[
            preferred ? 'border-brand-500 dark:border-brand-300' : 'border-elevation-3 dark:border-elevation-7',
            locked ? 'opacity-50 grayscale focus-within:opacity-100' : '',
        ]">
        <img v-if="iconUrl" :src="iconUrl" alt="" class="size-9 shrink-0 object-contain" loading="lazy">
        <UIcon v-else name="i-lucide-swords" class="size-9 shrink-0 text-muted" aria-hidden="true" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-highlighted wrap-anywhere">{{ name }}</p>
            <p class="mt-0.5 text-xs text-muted">{{ level }}</p>
        </div>
        <UButton :icon="preferred ? 'i-mdi-heart' : 'i-lucide-heart'" :color="preferred ? 'brand' : 'neutral'"
            variant="ghost" class="min-h-10 min-w-10 shrink-0 justify-center rounded-md" :loading="loading" :disabled="disabled"
            :aria-pressed="preferred" :aria-label="t(preferred ? 'characters.detail.unfavourite' : 'characters.detail.favourite', { name })"
            @click="emit('toggle')" />
    </div>
</template>
