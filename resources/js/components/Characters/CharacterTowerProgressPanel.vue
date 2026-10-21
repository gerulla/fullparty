<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAppearance } from '@/composables/useAppearance'
import { characterTowerSummary } from '@/utils/characterTowerSummary'
import type { CharacterTowerProgress } from '../../Types/Characters'

const props = defineProps<{ title: string; progress: CharacterTowerProgress }>()
const { t } = useI18n()
const { isDark } = useAppearance()
const summary = computed(() => characterTowerSummary(props.progress))
const fromLodestone = computed(() => props.progress.data_source === 'lodestone_achievement')
const clears = computed(() => t(fromLodestone.value && props.progress.clears > 0
    ? 'characters.card.forked_tower.clears_at_least' : 'characters.card.forked_tower.clears', { count: props.progress.clears }))
const kills = (value: number) => fromLodestone.value && value > 0
    ? t('characters.card.forked_tower.kills_at_least', { count: value }) : value
</script>

<template>
    <section class="space-y-3">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold text-highlighted">{{ title }}</h3>
            <UBadge :color="summary.cleared ? 'success' : 'neutral'" :variant="isDark ? 'outline' : 'solid'" class="rounded-md">
                {{ summary.cleared ? t('characters.overview.cleared') : t('characters.overview.bosses_defeated', { count: summary.defeated, total: summary.total }) }}
            </UBadge>
        </header>
        <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-muted">
            <p>{{ t('characters.card.forked_tower.clear_count') }}: <span class="font-medium text-default">{{ clears }}</span></p>
            <p v-if="summary.cleared" class="inline-flex items-center gap-1 text-xs">
                <UIcon name="i-lucide-shield-check" class="size-3.5" aria-hidden="true" />
                {{ t(fromLodestone ? 'characters.card.forked_tower.verified_with_lodestone' : 'characters.card.forked_tower.verified_with_fflogs') }}
            </p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div v-for="boss in progress.bosses" :key="boss.key" class="space-y-3 rounded-lg border border-elevation-3 bg-elevation-2 p-4 dark:border-elevation-7 dark:bg-elevation-5">
                <div class="flex items-center justify-between gap-3">
                    <h4 class="text-sm font-semibold">{{ t(`characters.card.forked_tower.bosses.${boss.key}`) }}</h4>
                    <UIcon v-if="boss.kills > 0" name="i-lucide-circle-check" class="size-4 shrink-0 text-green-700 dark:text-success" aria-hidden="true" />
                </div>
                <UProgress :model-value="Math.max(0, Math.min(100, boss.progress))" :max="100" :color="boss.kills > 0 ? 'success' : 'brand'"
                    :aria-label="t(`characters.card.forked_tower.bosses.${boss.key}`)" :ui="{ base: 'rounded-full', indicator: 'rounded-full' }" />
                <div class="flex justify-between gap-3 text-xs text-muted">
                    <span>{{ t('characters.card.forked_tower.kills') }}: <span class="font-semibold text-default">{{ kills(boss.kills) }}</span></span>
                    <span class="tabular-nums">{{ boss.progress }}%</span>
                </div>
            </div>
        </div>
    </section>
</template>
