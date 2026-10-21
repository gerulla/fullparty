<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import CharacterJobPreferenceCard from '@/components/Characters/CharacterJobPreferenceCard.vue'
import { characterClassTranslationKey, translateJobName } from '@/utils/characterJobTranslations'
import type { CharacterClassProgress, CharacterManagementActions } from '../../Types/Characters'

const props = defineProps<{ jobs: CharacterClassProgress[]; actions: CharacterManagementActions }>()
const { t } = useI18n()
const roles = [
    { role: 'tank', label: 'general.roles.tank' },
    { role: 'healer', label: 'general.roles.healer' },
    { role: 'melee dps', label: 'general.roles.melee_dps' },
    { role: 'physical ranged dps', label: 'general.roles.physical_ranged_dps' },
    { role: 'magic ranged dps', label: 'general.roles.magic_ranged_dps' },
]
const groups = computed(() => roles.map(role => ({ ...role, jobs: props.jobs.filter(job => job.role === role.role) })).filter(group => group.jobs.length))
</script>

<template>
    <section class="space-y-5">
        <div>
            <h3 class="text-sm font-semibold text-highlighted">{{ t('characters.detail.your_jobs') }}</h3>
            <p class="mt-1 text-sm text-muted">{{ t('characters.detail.preferences_help') }}</p>
        </div>
        <section v-for="group in groups" :key="group.role" class="space-y-2">
            <h4 class="text-sm font-medium text-muted">{{ t(group.label) }}</h4>
            <div class="grid gap-2 sm:grid-cols-2">
                <CharacterJobPreferenceCard v-for="job in group.jobs" :key="job.id"
                    :name="translateJobName(t, characterClassTranslationKey(job), job.name)" :icon-url="job.icon_url"
                    :level="t('characters.card.level', { level: job.level })" :locked="job.level === 0" :preferred="job.is_preferred"
                    :loading="actions.pendingAction === `class:${job.id}`" :disabled="actions.busy" @toggle="actions.toggleClass(job)" />
            </div>
        </section>
    </section>
</template>
