<script setup lang="ts">
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useAppearance } from '@/composables/useAppearance'
import { characterTowerSummary } from '@/utils/characterTowerSummary'
import { characterVerificationStatus } from '@/utils/characterVerificationStatus'
import { characterClassTranslationKey, translateJobName } from '@/utils/characterJobTranslations'
import type { AccountCharacter } from '../../Types/Characters'

const props = defineProps<{ character: AccountCharacter }>()
const emit = defineEmits<{ manage: [event: MouseEvent] }>()
const { t } = useI18n()
const { isDark } = useAppearance()

const status = computed(() => characterVerificationStatus(props.character))
const preferredClasses = computed(() => props.character.classes.filter(job => job.is_preferred))
const towers = computed(() => [
    { key: 'blood', label: 'characters.card.sections.forked_tower_blood', ...characterTowerSummary(props.character.occult.blood_progress) },
    { key: 'magic', label: 'characters.card.sections.forked_tower_magic', ...characterTowerSummary(props.character.occult.magic_progress) },
])
</script>

<template>
    <article class="flex min-w-0 flex-col rounded-xl border border-elevation-3 bg-elevation-1 p-5 shadow-sm transition-colors duration-150 hover:border-brand-400/60 motion-reduce:transition-none dark:border-elevation-7 dark:bg-elevation-3 sm:p-6"
        :aria-labelledby="`character-${character.id}-name`">
        <div class="mb-5 flex items-center justify-between gap-3">
            <UAvatar :src="character.avatar_url ?? undefined" :alt="character.name" size="3xl"
                class="size-18 shrink-0 bg-elevation-2 text-brand-700 dark:bg-elevation-5 dark:text-brand-300" />
            <UBadge :color="status.color" :icon="status.icon" :variant="isDark ? 'outline' : 'solid'" size="sm" class="rounded-md">
                {{ t(`characters.verification_guide.states.${status.key}.title`) }}
            </UBadge>
        </div>

        <div class="mb-5">
            <h2 :id="`character-${character.id}-name`" class="text-base font-semibold text-highlighted wrap-anywhere">
                {{ character.name }}
                <UTooltip v-if="character.is_primary" :text="t('dashboard.character_selector.primary_character')">
                    <UIcon name="i-lucide-crown" class="ml-1 inline size-4 align-[-2px] text-amber-700 dark:text-gold"
                        role="img" :aria-label="t('dashboard.character_selector.primary_character')" />
                </UTooltip>
            </h2>
            <p class="mt-1 text-sm text-muted">{{ [character.world, character.datacenter].filter(Boolean).join(' · ') }}</p>
        </div>

        <dl class="space-y-3 border-y border-elevation-3 py-4 text-sm dark:border-elevation-7">
            <div class="flex items-start justify-between gap-3">
                <dt class="text-muted">{{ t('characters.card.knowledge_level') }}</dt>
                <dd class="shrink-0 font-semibold tabular-nums">{{ character.occult.knowledge_level }}</dd>
            </div>
            <div v-for="tower in towers" :key="tower.key" class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                <dt class="min-w-0 text-muted">{{ t(tower.label) }}</dt>
                <dd class="ml-auto inline-flex items-center gap-1 text-right font-medium" :class="tower.cleared ? 'text-green-700 dark:text-success' : 'text-default'">
                    <UIcon v-if="tower.cleared" name="i-lucide-check" class="size-3.5 shrink-0" aria-hidden="true" />
                    {{ tower.cleared ? t('characters.overview.cleared') : t('characters.overview.bosses_defeated', { count: tower.defeated, total: tower.total }) }}
                </dd>
            </div>
        </dl>

        <div class="mb-6 mt-5 flex-1">
            <h3 class="mb-2 text-xs font-medium tracking-wide text-muted uppercase">{{ t('characters.overview.preferred_jobs') }}</h3>
            <div v-if="preferredClasses.length" class="flex flex-wrap gap-2">
                <UTooltip v-for="job in preferredClasses" :key="job.id" :text="translateJobName(t, characterClassTranslationKey(job), job.name)">
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-elevation-2 px-2 py-1 text-xs font-medium dark:bg-elevation-5"
                        :aria-label="translateJobName(t, characterClassTranslationKey(job), job.name)">
                        <img v-if="job.icon_url" :src="job.icon_url" alt="" class="size-5 object-contain" loading="lazy">
                        {{ job.shorthand.toUpperCase() }}
                    </span>
                </UTooltip>
            </div>
            <p v-else class="text-sm text-muted">{{ t('characters.overview.no_preferred_jobs') }}</p>
        </div>

        <div class="space-y-3">
            <UButton :id="`manage-character-${character.id}`" block :color="character.is_primary ? 'brand' : 'neutral'" :variant="character.is_primary ? 'solid' : 'outline'"
                trailing-icon="i-lucide-arrow-right" class="justify-center rounded-md py-2.5"
                :aria-label="t('characters.overview.manage_named', { name: character.name })" @click="emit('manage', $event)">
                {{ t('characters.overview.manage') }}
            </UButton>
            <div v-if="character.claimed_at" class="flex min-h-10 items-center justify-center gap-2 text-sm text-muted">
                <UIcon name="i-lucide-shield-check" class="size-4" aria-hidden="true" />
                {{ t('characters.overview.ownership_confirmed') }}
            </div>
            <div v-else class="text-center">
                <UButton :to="route('characters.claim', character.id)" block color="neutral" variant="ghost" icon="i-lucide-shield" class="justify-center rounded-md">
                    {{ t('characters.overview.claim') }}
                </UButton>
            </div>
        </div>
    </article>
</template>
