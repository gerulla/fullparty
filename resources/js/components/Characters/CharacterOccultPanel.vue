<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import CharacterJobPreferenceCard from '@/components/Characters/CharacterJobPreferenceCard.vue'
import { phantomJobTranslationKey, translateJobName } from '@/utils/characterJobTranslations'
import type { AccountCharacter, CharacterManagementActions } from '../../Types/Characters'

defineProps<{ occult: AccountCharacter['occult']; actions: CharacterManagementActions }>()
const { t } = useI18n()
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center justify-between gap-4 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-5">
            <h3 class="text-sm font-semibold">{{ t('characters.card.knowledge_level') }}</h3>
            <p class="text-2xl font-semibold tabular-nums text-brand-700 dark:text-brand">{{ occult.knowledge_level }}</p>
        </div>
        <div>
            <h3 class="text-sm font-semibold text-highlighted">{{ t('characters.detail.phantom_jobs') }}</h3>
            <p class="mt-1 text-sm text-muted">{{ t('characters.detail.preferences_help') }}</p>
        </div>
        <div class="grid gap-2 sm:grid-cols-2">
            <CharacterJobPreferenceCard v-for="job in occult.phantom_jobs" :key="job.id"
                :name="translateJobName(t, phantomJobTranslationKey(job), job.name)"
                :icon-url="job.is_maxed ? job.icon_url : job.black_icon_url || job.icon_url"
                :level="t('characters.card.phantom_level', { current: job.current_level, max: job.max_level })"
                :locked="job.current_level === 0" :preferred="job.is_preferred" :loading="actions.pendingAction === `phantom:${job.id}`"
                :disabled="actions.busy" @toggle="actions.togglePhantomJob(job)" />
        </div>
    </section>
</template>
