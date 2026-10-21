<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import CharacterCandidateRow from './CharacterCandidateRow.vue'
import CharacterImportStageAnimation from './CharacterImportStageAnimation.vue'
import type { CharacterCandidate, CharacterImportProgress } from '../../Types/CharacterOnboarding'

const props = defineProps<{ character: CharacterCandidate | null; progress: CharacterImportProgress }>()
const { t } = useI18n()
const title = computed(() => t(`characters.onboarding.progress.${props.progress.stage}.title`))
</script>

<template>
    <div class="mx-auto flex w-full max-w-xl flex-col items-center gap-6 py-6 text-center sm:py-10">
        <CharacterImportStageAnimation :key="progress.stage" :stage="progress.stage" />
        <div class="w-full min-h-16" role="status" aria-live="polite" aria-atomic="true">
            <h1 tabindex="-1" class="text-xl font-semibold text-highlighted outline-none">{{ title }}</h1>
            <p class="mt-3 text-sm leading-relaxed text-muted">{{ t(`characters.onboarding.progress.${progress.stage}.subtitle`) }}</p>
        </div>
        <div class="w-full">
            <UProgress :model-value="progress.percent" :max="100" color="brand" size="lg" :aria-label="title"
                :ui="{ base: 'bg-elevation-3 dark:bg-elevation-5 rounded-full', indicator: 'rounded-full transition-transform duration-300 motion-reduce:transition-none' }" />
        </div>
        <div v-if="character" class="w-full rounded-lg bg-elevation-2 p-4 dark:bg-elevation-4">
            <CharacterCandidateRow :character="character" />
        </div>
        <p class="text-xs text-muted">{{ t('characters.onboarding.loading_wait') }}</p>
    </div>
</template>
