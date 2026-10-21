<script setup lang="ts">
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useAppearance } from '@/composables/useAppearance'
import { characterVerificationStatus } from '@/utils/characterVerificationStatus'
import CharacterJobsPanel from '@/components/Characters/CharacterJobsPanel.vue'
import CharacterOccultPanel from '@/components/Characters/CharacterOccultPanel.vue'
import CharacterTowerProgressPanel from '@/components/Characters/CharacterTowerProgressPanel.vue'
import type { AccountCharacter, CharacterManagementActions } from '../../Types/Characters'

const props = defineProps<{ character: AccountCharacter; actions: CharacterManagementActions }>()
const emit = defineEmits<{ verificationHelp: [event: MouseEvent] }>()
const { t } = useI18n()
const { isDark } = useAppearance()
const activeTab = ref('jobs')
const status = computed(() => characterVerificationStatus(props.character))
const tabs = computed(() => [
    { label: t('characters.detail.tabs.jobs'), value: 'jobs', slot: 'jobs' },
    { label: t('characters.detail.tabs.occult'), value: 'occult', slot: 'occult' },
    { label: t('characters.detail.tabs.progression'), value: 'progression', slot: 'progression' },
])
</script>

<template>
    <article class="overflow-hidden rounded-xl border border-elevation-3 bg-elevation-1 dark:border-elevation-7 dark:bg-elevation-3">
        <header class="flex flex-wrap items-center justify-between gap-5 p-5 sm:p-6">
            <div class="min-w-0 space-y-3">
                <div class="flex items-center gap-3">
                    <UAvatar :src="character.avatar_url ?? undefined" :alt="character.name" size="xl" class="size-12 shrink-0 bg-elevation-2 dark:bg-elevation-5" />
                    <div class="min-w-0">
                        <h2 class="font-semibold text-highlighted wrap-anywhere">
                            {{ character.name }}
                            <UTooltip v-if="character.is_primary" :text="t('dashboard.character_selector.primary_character')">
                                <UIcon name="i-lucide-crown" class="ml-1 inline size-4 align-[-2px] text-amber-700 dark:text-gold"
                                    role="img" :aria-label="t('dashboard.character_selector.primary_character')" />
                            </UTooltip>
                        </h2>
                        <p class="mt-0.5 text-sm text-muted">{{ [character.world, character.datacenter].filter(Boolean).join(' · ') }}</p>
                    </div>
                </div>
                <UBadge :color="status.color" :icon="status.icon" :variant="isDark ? 'outline' : 'solid'" size="sm" class="rounded-md">
                    {{ t(`characters.verification_guide.states.${status.key}.title`) }}
                </UBadge>
            </div>
            <div class="flex flex-wrap gap-2">
                <UButton v-if="!character.is_primary && character.verified_at" color="neutral" variant="outline" icon="i-lucide-crown"
                    class="rounded-md" :disabled="actions.busy" :loading="actions.pendingAction === 'primary'" @click="actions.makePrimary">
                    {{ t('characters.card.make_primary') }}
                </UButton>
                <UButton color="neutral" variant="outline" icon="i-lucide-refresh-cw" class="rounded-md"
                    :disabled="actions.busy" :loading="actions.pendingAction === 'refresh'" @click="actions.refresh">
                    {{ t('characters.detail.refresh') }}
                </UButton>
            </div>
        </header>

        <UAlert v-if="actions.error" color="error" variant="subtle" icon="i-lucide-circle-alert" :title="actions.error" role="alert" class="mx-5 mb-4 w-auto rounded-lg sm:mx-6" />

        <UTabs v-model="activeTab" :items="tabs" color="brand" variant="link" :ui="{
            root: 'items-stretch gap-0',
            list: 'grid w-full grid-cols-3 items-stretch gap-2 rounded-none border-b border-elevation-3 bg-transparent px-5 dark:border-elevation-7 sm:flex sm:justify-start sm:gap-5 sm:px-6',
            trigger: 'min-w-0 shrink-0 grow-0 rounded-none border-b-2 border-transparent px-0 py-3 text-xs data-[state=active]:border-brand-700 dark:data-[state=active]:border-brand-300 sm:text-sm',
            label: 'whitespace-normal text-center sm:whitespace-nowrap',
            indicator: 'hidden',
            content: 'p-5 sm:p-6',
        }">
            <template #jobs>
                <CharacterJobsPanel :jobs="character.classes" :actions="actions" />
            </template>
            <template #occult>
                <CharacterOccultPanel :occult="character.occult" :actions="actions" />
            </template>
            <template #progression>
                <div class="space-y-8">
                    <p class="text-sm text-muted">{{ t('characters.detail.progress_help') }}</p>
                    <CharacterTowerProgressPanel :title="t('characters.card.sections.forked_tower_blood')" :progress="character.occult.blood_progress" />
                    <CharacterTowerProgressPanel :title="t('characters.card.sections.forked_tower_magic')" :progress="character.occult.magic_progress" />
                </div>
            </template>
        </UTabs>

        <aside class="mx-5 mb-6 flex flex-wrap items-center gap-3 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-5 sm:mx-6">
            <UIcon :name="status.icon" class="size-5 shrink-0 text-muted" aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold text-highlighted">{{ t(`characters.detail.ownership.${status.key}.title`) }}</h3>
                <p class="mt-1 text-xs text-muted">{{ t(`characters.detail.ownership.${status.key}.description`) }}</p>
            </div>
            <div v-if="!character.claimed_at" class="space-y-1">
                <UButton :to="route('characters.claim', character.id)" color="brand" class="rounded-md">{{ t('characters.overview.claim') }}</UButton>
            </div>
            <UButton v-else color="neutral" variant="ghost" icon="i-lucide-circle-help" class="rounded-md"
                :aria-label="t('characters.overview.verification_button')" @click="emit('verificationHelp', $event)" />
        </aside>

        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-elevation-3 p-5 dark:border-elevation-7 sm:px-6">
            <UButton :href="`https://na.finalfantasyxiv.com/lodestone/character/${character.lodestone_id}`" target="_blank" rel="noopener noreferrer"
                color="brand" variant="link" trailing-icon="i-lucide-external-link" class="rounded-md px-0">
                {{ t('characters.card.view_lodestone_profile') }}
            </UButton>
            <UButton color="neutral" variant="ghost" icon="i-lucide-unlink" class="rounded-md hover:text-error"
                :disabled="actions.busy" :loading="actions.pendingAction === 'remove'" @click="actions.remove">
                {{ t('characters.detail.remove') }}
            </UButton>
        </footer>
    </article>
</template>
