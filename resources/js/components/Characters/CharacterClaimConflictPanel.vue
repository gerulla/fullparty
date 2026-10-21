<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CharacterClaimConflictStage } from '../../Types/CharacterClaim'

const props = defineProps<{ stage: CharacterClaimConflictStage; discordUrl: string | null }>()
const emit = defineEmits<{ claim: [] }>()
const { t } = useI18n()
const icon = computed(() => ({
    linked_elsewhere: 'i-lucide-user-lock',
    claimed_elsewhere: 'i-lucide-shield-alert',
})[props.stage])
</script>

<template>
    <div class="flex flex-col items-center gap-5 px-6 py-10 text-center" role="status" aria-live="polite">
        <UIcon :name="icon" class="size-12 text-brand" aria-hidden="true" />
        <div class="max-w-lg space-y-3">
            <h2 class="text-lg font-semibold text-highlighted">{{ t(`characters.claim_flow.conflicts.${stage}.title`) }}</h2>
            <p class="text-sm leading-relaxed text-muted">{{ t(`characters.claim_flow.conflicts.${stage}.description`) }}</p>
        </div>
        <UButton v-if="stage === 'linked_elsewhere'" color="brand" icon="i-lucide-shield-check" class="rounded-md" @click="emit('claim')">
            {{ t('characters.claim_flow.claim_with_code') }}
        </UButton>
        <UButton v-else-if="stage === 'claimed_elsewhere'" :href="discordUrl ?? undefined" :disabled="!discordUrl"
            target="_blank" rel="noopener noreferrer" color="brand" icon="ic:baseline-discord"
            trailing-icon="i-lucide-arrow-up-right" class="rounded-md">
            {{ t('characters.claim_flow.support_discord') }}
        </UButton>
    </div>
</template>
