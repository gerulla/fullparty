<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import { useAppearance } from '@/composables/useAppearance'
import { useCharacterVerificationGuide } from '@/composables/useCharacterVerificationGuide'
import CharacterCandidateRow from './CharacterCandidateRow.vue'
import CharacterTransferNotice from './CharacterTransferNotice.vue'
import type { AddedCharacter, CharacterAddMethod } from '../../Types/CharacterOnboarding'

const props = defineProps<{
    character: AddedCharacter
    method: CharacterAddMethod
    preview: boolean
    refreshFailed: boolean
    partial: boolean
    transferred?: boolean
}>()
const emit = defineEmits<{ retry: [] }>()
const { t } = useI18n()
const page = usePage()
const { isDark } = useAppearance()
const { showVerificationGuide } = useCharacterVerificationGuide()
const statusColor = computed(() => props.character.status === 'claimed' ? 'brand' : props.character.status === 'verified' ? 'success' : 'error')
const nextSteps = computed(() => [
    { key: 'groups', icon: 'i-lucide-users', href: route('groups.index') },
    { key: 'runs', icon: 'i-lucide-calendar-days', href: route('dashboard.runs.index') },
    { key: 'profile', icon: 'i-lucide-user-pen', href: route('profile.show', { user: page.props.auth.user.id }) },
])
</script>

<template>
    <div class="mx-auto flex w-full max-w-2xl flex-col items-center gap-6 py-3 text-center sm:py-5">
        <svg class="size-20 shrink-0 text-success sm:size-24" viewBox="0 0 96 96" fill="none" aria-hidden="true" focusable="false">
            <circle class="success-circle" cx="48" cy="48" r="38" pathLength="1" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
            <path class="success-check" d="M30 48L42 60L67 35" pathLength="1" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
        </svg>

        <div class="max-w-lg space-y-2" aria-live="polite">
            <h1 tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">{{ t('characters.onboarding.complete_title') }}</h1>
            <p class="text-sm leading-relaxed text-muted">{{ t(`characters.onboarding.complete_${character.status}`) }}</p>
        </div>

        <div class="flex w-full max-w-96 items-center gap-3 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-4">
            <CharacterCandidateRow :character="character" class="flex-1" />
            <UBadge :color="statusColor" :variant="isDark ? 'outline' : 'solid'" class="shrink-0 rounded-md">
                {{ t(`characters.verification_guide.states.${character.status}.title`) }}
            </UBadge>
        </div>

        <CharacterTransferNotice v-if="transferred" />

        <div v-if="refreshFailed || partial" class="w-full space-y-3">
            <UAlert color="warning" variant="soft" icon="i-lucide-triangle-alert"
                :description="t(`characters.onboarding.${refreshFailed ? 'refresh_failed' : 'refresh_partial'}`)"
                class="rounded-lg text-left" />
            <UButton color="neutral" variant="outline" icon="i-lucide-refresh-cw" class="rounded-md" @click="emit('retry')">
                {{ t('characters.onboarding.retry') }}
            </UButton>
        </div>

        <section class="w-full space-y-2">
            <h2 class="font-semibold text-highlighted">{{ t('characters.onboarding.next_steps.title') }}</h2>
            <p class="text-sm leading-relaxed text-muted">{{ t('characters.onboarding.next_steps.description') }}</p>
            <div class="flex flex-wrap justify-center gap-1 sm:gap-3">
                <UButton v-for="item in nextSteps" :key="item.key" :to="item.href" :icon="item.icon"
                    color="neutral" variant="ghost" class="rounded-md">
                    {{ t(`characters.onboarding.next_steps.${item.key}`) }}
                </UButton>
            </div>
        </section>

        <section v-if="method === 'lodestone' && character.status !== 'claimed'"
            class="w-full space-y-4 border-t border-elevation-3 pt-6 dark:border-elevation-7">
            <h2 class="font-semibold text-highlighted">{{ t('characters.onboarding.verification_next_title') }}</h2>
            <div class="grid gap-4 text-left sm:grid-cols-2">
                <div class="flex flex-col items-start gap-2 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-4">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                        <UIcon name="i-lucide-users" class="size-4 shrink-0 text-muted" aria-hidden="true" />
                        {{ t('characters.onboarding.participation_title') }}
                    </h3>
                    <p class="text-sm leading-relaxed text-muted">{{ t('characters.onboarding.participation_description') }}</p>
                    <UButton color="neutral" variant="ghost" class="mt-auto rounded-md" @click="showVerificationGuide">
                        {{ t('characters.onboarding.participation_help') }}
                    </UButton>
                </div>
                <div class="flex flex-col items-start gap-2 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-4">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                        <UIcon name="i-lucide-shield-check" class="size-4 shrink-0 text-muted" aria-hidden="true" />
                        {{ t('characters.onboarding.claim_title') }}
                    </h3>
                    <p class="text-sm leading-relaxed text-muted">{{ t('characters.onboarding.claim_description') }}</p>
                    <UButton :to="preview ? undefined : route('characters.claim', character.id)" :disabled="preview"
                        color="brand" variant="outline" trailing-icon="i-lucide-arrow-right" class="mt-auto rounded-md">
                        {{ t('characters.onboarding.claim_now') }}
                    </UButton>
                </div>
            </div>
        </section>

        <UButton :to="route('account.characters')" color="brand" size="lg" trailing-icon="i-lucide-arrow-right" class="rounded-md">
            {{ t('characters.onboarding.my_characters') }}
        </UButton>
    </div>
</template>

<style scoped>
.success-circle,
.success-check {
    stroke-dasharray: 1;
    stroke-dashoffset: 0;
}

.success-circle {
    transform: rotate(-90deg);
    transform-origin: center;
    animation: success-draw 700ms ease-out both;
}

.success-check {
    animation: success-draw 400ms ease-out 500ms both;
}

@keyframes success-draw {
    from { opacity: 0; stroke-dashoffset: 1; }
    to { opacity: 1; stroke-dashoffset: 0; }
}

@media (prefers-reduced-motion: reduce) {
    .success-circle,
    .success-check {
        animation: none;
    }
}
</style>
