<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RunSearchOption } from '@/Types/RunDiscovery'

const props = defineProps<{ options: RunSearchOption[]; selected: string; placeholder: string; anyLabel: string }>()
const emit = defineEmits<{ select: [value: string] }>()
const { t } = useI18n()
const query = ref('')
const filtered = computed(() => props.options.filter(option => option.label.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())))
const optionClasses = [
    'w-full justify-start rounded-xl px-3 py-3',
    'dark:hover:bg-neutral-700 dark:focus-visible:bg-neutral-700 dark:focus-visible:ring-2 dark:focus-visible:ring-inset dark:focus-visible:ring-brand-400',
    'dark:aria-pressed:bg-brand-400/20 dark:aria-pressed:hover:bg-brand-400/35 dark:aria-pressed:focus-visible:bg-brand-400/35',
]
</script>

<template>
    <div class="space-y-3 p-4">
        <UInput v-model="query" icon="i-lucide-search" :placeholder="placeholder" :aria-label="placeholder"
            class="w-full" :ui="{ base: 'rounded-xl bg-elevation-2 text-highlighted placeholder:text-muted shadow-sm ring-0 focus-visible:ring-2 focus-visible:ring-brand-700 dark:bg-elevation-6 dark:shadow-black/35 dark:focus-visible:ring-brand-400', leadingIcon: 'text-muted' }" />
        <div class="max-h-80 space-y-1 overflow-y-auto">
            <UButton type="button" color="neutral" :variant="!selected ? 'soft' : 'ghost'"
                :class="optionClasses" icon="i-lucide-globe" :label="anyLabel"
                :aria-pressed="!selected" @click="emit('select', '')" />
            <UButton v-for="option in filtered" :key="option.value" type="button" color="neutral"
                :variant="selected === option.value ? 'soft' : 'ghost'" :aria-pressed="selected === option.value"
                :class="optionClasses" class="gap-3 text-left" @click="emit('select', option.value)">
                <img v-if="option.image" :src="option.image" alt="" class="size-10 shrink-0 rounded-lg object-cover" loading="lazy">
                <span class="min-w-0 flex-1 whitespace-normal">{{ option.label }}</span>
                <UIcon v-if="selected === option.value" name="i-lucide-check" class="size-4 shrink-0 text-brand-700 dark:text-brand-400" />
            </UButton>
            <p v-if="!filtered.length" class="p-3 text-sm text-muted">{{ t('runs.search.no_options') }}</p>
        </div>
    </div>
</template>
