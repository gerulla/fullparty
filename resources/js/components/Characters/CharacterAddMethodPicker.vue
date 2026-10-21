<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { CharacterAddMethod } from '../../Types/CharacterOnboarding'
defineProps<{ linked: boolean }>()
const emit = defineEmits<{ choose: [method: CharacterAddMethod] }>()
const { t } = useI18n()
const methods = ['xivauth', 'lodestone'] as const
const benefits = {
    xivauth: [
        { key: 'no_manual_verification', icon: 'i-lucide-check' },
        { key: 'automatic_claim', icon: 'i-lucide-check' },
    ],
    lodestone: [
        { key: 'unverified', icon: 'i-lucide-users-round' },
        { key: 'all_worlds', icon: 'i-lucide-search' },
    ],
} as const
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <button v-for="method in methods" :key="method" type="button"
            class="flex flex-col items-center rounded-xl border border-elevation-3 bg-elevation-1 p-6 text-center transition-colors hover:border-brand-500 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand dark:border-elevation-7 dark:bg-elevation-3 dark:hover:border-brand-400"
            @click="emit('choose', method)">
            <span class="mb-5 flex h-24 w-full items-center justify-center" aria-hidden="true">
                <img v-if="method === 'xivauth'" :src="'/logos/providers/xivauth.svg'" alt="" width="224" height="65"
                    class="h-auto w-56 max-w-full opacity-80 grayscale brightness-0 dark:invert" draggable="false" />
                <!-- The official Lodestone icon has a dark background; blending keeps only its monochrome mark visible. -->
                <img v-else :src="'/logos/providers/lodestone.png'" alt="" width="80" height="80"
                    class="size-20 opacity-80 grayscale brightness-[3] contrast-[3] invert mix-blend-multiply dark:invert-0 dark:mix-blend-screen" draggable="false" />
            </span>
            <span class="flex flex-wrap items-center justify-center gap-2">
                <span class="text-lg font-semibold text-highlighted">{{ t(`characters.onboarding.methods.${method}.title`) }}</span>
                <UBadge v-if="method === 'xivauth'" color="brand" variant="soft" size="md" class="rounded-md">
                    {{ t('characters.onboarding.recommended') }}
                </UBadge>
            </span>
            <span class="mt-3 w-full text-left text-sm leading-relaxed text-muted">
                {{ t(`characters.onboarding.methods.${method}.description`) }}
            </span>
            <span class="mt-5 w-full space-y-3 text-left text-sm text-default">
                <span v-for="benefit in benefits[method]" :key="benefit.key" class="flex items-start gap-3 leading-relaxed">
                    <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full"
                        :class="method === 'xivauth' ? 'bg-success/10 text-success' : 'bg-info/10 text-info'" aria-hidden="true">
                        <UIcon :name="benefit.icon" class="size-3" />
                    </span>
                    <span>{{ t(`characters.onboarding.methods.${method}.benefits.${benefit.key}`) }}</span>
                </span>
            </span>
            <span class="mt-auto pt-7 text-sm font-semibold text-brand-700 dark:text-brand-300">
                {{ method === 'xivauth' && !linked ? t('characters.onboarding.connect') : t('characters.onboarding.continue') }}
            </span>
        </button>
    </div>
</template>
