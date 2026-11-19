<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AttendeeActivity } from '@/Types/ActivityAttendee'
import type { ActivitySlot, RosterCompositionTone } from '@/Types/ActivityRoster'
import { useAllianceProgress } from '@/composables/useAllianceProgress'
import { useRunOverviewPreferences } from '@/composables/useRunOverviewPreferences'
import { useRosterSummary } from '@/composables/useRosterSummary'
import { compositionSlotTones } from '@/utils/rosterComposition'
import { getRunStatusIcon } from '@/utils/runStatusIcons'
import ActivityAttendeeRosterSlot from './ActivityAttendeeRosterSlot.vue'
import AllianceProgressLegend from './AllianceProgressLegend.vue'
import RosterDesignationLegend from './RosterDesignationLegend.vue'

const props = defineProps<{
    activity: AttendeeActivity
    groupSlug: string
    partyCount: number
    showSelf: boolean
}>()

const { t } = useI18n()
const { plainDpsEnabled, numberedSecondaryPartiesEnabled, allianceProgressEnabled } = useRunOverviewPreferences()
const viewOptionsOpen = ref(false)
const boardKey = 'groups.activities.overview.board'
const displayOptions = [
    { label: 'progression.toggle', hint: 'progress', enabled: allianceProgressEnabled },
    { label: 'plain_dps', hint: 'dps', enabled: plainDpsEnabled },
    { label: 'party_label_mode', hint: 'parties', enabled: numberedSecondaryPartiesEnabled },
]
const switchUi = {
    root: 'max-xl:min-h-14 max-xl:flex-row-reverse max-xl:items-center max-xl:justify-between max-xl:gap-3 max-xl:px-4 max-xl:py-3',
    wrapper: 'max-xl:ms-0 max-xl:min-w-0',
    label: 'cursor-pointer max-xl:text-sm max-xl:after:absolute max-xl:after:inset-0',
    container: 'relative z-1 shrink-0',
    description: 'mt-0.5 text-xs leading-snug text-muted xl:hidden',
}
const selectedKey = ref<string | number>(0)
const selectedPreset = computed(() => props.activity.roster_summary_presets.find(preset => preset.key === selectedKey.value) ?? null)
const { localizedText, matches, missingGroups } = useRosterSummary(
    () => selectedPreset.value, () => props.activity.slots, () => numberedSecondaryPartiesEnabled.value,
)
const options = computed(() => [
    { label: t('groups.activities.overview.board.composition.none'), value: 0 },
    ...props.activity.roster_summary_presets.map(preset => ({ label: localizedText(preset.label, preset.key), value: preset.key })),
])
const slotTones = computed(() => compositionSlotTones(matches.value))
function compositionTone(slot: ActivitySlot): RosterCompositionTone | undefined {
    return selectedPreset.value ? slotTones.value.get(slot.id) ?? 'neutral' : undefined
}
const mainSlots = computed(() => props.activity.slots.filter(slot => !slot.is_bench && !slot.is_fill_in))
const filled = computed(() => mainSlots.value.filter(slot => slot.assigned_character_id !== null).length)
const cardClass = 'min-w-0 rounded-xl border border-elevation-3 bg-elevation-1 text-default shadow-sm dark:border-elevation-6 dark:bg-elevation-3 dark:shadow-none dark:[--ui-primary:var(--color-brand-400)]'
const reserveCards = computed(() => [
    { kind: 'bench', title: t('groups.activities.overview.board.bench_title'), icon: getRunStatusIcon('on_bench'),
        empty: t('groups.activities.overview.board.no_bench_slots') },
    { kind: 'fill_in', title: t('groups.activities.management.roster.fill_ins.title'), icon: getRunStatusIcon('filled_in'),
        empty: t('groups.activities.overview.board.no_fill_in_slots') },
].map(card => {
    const slots = props.activity.slots.filter(slot => slot.slot_kind === card.kind).sort((a, b) => a.sort_order - b.sort_order)
    return { ...card, slots, filled: slots.filter(slot => slot.assigned_character_id !== null).length }
}))
const progression = useAllianceProgress({
    get enabled() { return allianceProgressEnabled.value && !selectedPreset.value },
    get groupSlug() { return props.groupSlug },
    get activityId() { return props.activity.id },
    get targetProgPointKey() { return props.activity.target_prog_point_key },
    get slots() { return props.activity.slots },
})

function progressStatus(slot: ActivitySlot) {
    return allianceProgressEnabled.value && slot.assigned_character_id !== null
        ? progression.records.value[slot.assigned_character_id]?.status ?? null
        : null
}
</script>

<template>
    <section aria-labelledby="run-overview-roster-title" :class="cardClass">
        <header class="space-y-3 border-b border-default px-4 py-3 sm:px-6">
            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-3 xl:flex xl:flex-wrap xl:justify-between xl:gap-x-6">
                <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                    <h2 id="run-overview-roster-title" class="flex items-center gap-2 font-semibold sm:gap-3 sm:text-lg">
                        <UIcon name="i-lucide-users" class="size-5 shrink-0 text-muted sm:size-6" aria-hidden="true" />
                        {{ t('groups.activities.overview.meta.roster') }}
                    </h2>
                    <UBadge color="neutral" variant="soft" class="rounded-full tabular-nums"
                        :label="t('groups.activities.overview.roster_capacity', { assigned: filled, total: mainSlots.length })" />
                    <span class="text-sm text-muted">{{ t('groups.activities.overview.board.party_count', { count: partyCount }) }}</span>
                    <span class="text-sm text-muted">{{ t('groups.activities.overview.board.open_count', { count: mainSlots.length - filled }) }}</span>
                </div>
                <UButton icon="i-lucide-sliders-horizontal" color="neutral" :variant="viewOptionsOpen ? 'soft' : 'ghost'"
                    class="size-10 justify-center rounded-md md:h-auto md:w-auto xl:hidden"
                    :aria-label="t('groups.activities.overview.board.view_options')" :aria-expanded="viewOptionsOpen"
                    aria-controls="run-overview-roster-options" @click="viewOptionsOpen = !viewOptionsOpen">
                    <span class="hidden md:inline">{{ t('groups.activities.overview.board.view_options') }}</span>
                    <UIcon name="i-lucide-chevron-down" aria-hidden="true" class="hidden size-4 md:block"
                        :class="{ 'rotate-180': viewOptionsOpen }" />
                </UButton>
                <div id="run-overview-roster-options"
                    class="col-span-full min-w-0 max-xl:rounded-xl max-xl:bg-elevation-1 max-xl:shadow-sm max-xl:ring-1 max-xl:ring-elevation-3 dark:max-xl:bg-elevation-4 dark:max-xl:ring-elevation-6 xl:flex xl:flex-wrap xl:items-center xl:gap-x-5 xl:gap-y-3"
                    :class="viewOptionsOpen ? 'block' : 'hidden'">
                    <div class="grid divide-y divide-elevation-3 dark:divide-elevation-6 md:grid-cols-3 md:divide-x md:divide-y-0 xl:contents xl:divide-x-0">
                        <USwitch v-for="option in displayOptions" :key="option.label" v-model="option.enabled.value" size="sm"
                            :ui="switchUi" :label="t(boardKey + '.' + option.label)"
                            :aria-describedby="'roster-view-hint-' + option.hint">
                            <template #description><span :id="'roster-view-hint-' + option.hint">{{ t(boardKey + '.view_hints.' + option.hint) }}</span></template>
                        </USwitch>
                    </div>
                    <UFormField v-if="activity.roster_summary_presets.length" orientation="horizontal"
                        :label="t(boardKey + '.composition.label')" :description="t(boardKey + '.view_hints.composition')"
                        class="min-w-0 max-w-full flex-col items-stretch gap-2 rounded-b-xl max-xl:border-t max-xl:border-elevation-3 max-xl:bg-elevation-2/50 max-xl:px-4 max-xl:py-3 dark:max-xl:border-elevation-6 dark:max-xl:bg-elevation-5/60 md:flex-row md:items-center xl:flex-wrap xl:justify-start"
                        :ui="{ container: 'w-full min-w-0 md:w-64 md:shrink-0', description: 'mt-0.5 text-xs leading-snug xl:hidden' }">
                        <USelect :model-value="selectedPreset?.key ?? 0" :items="options" size="sm"
                            class="w-full rounded-md max-xl:min-h-11 max-xl:bg-elevation-1 dark:max-xl:bg-elevation-3" :ui="{ itemLabel: 'whitespace-normal wrap-anywhere' }"
                            @update:model-value="selectedKey = $event" />
                    </UFormField>
                </div>
            </div>
            <div v-if="selectedPreset" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
                <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-green-600 dark:bg-green-400" />{{ t('groups.activities.overview.board.composition.matched') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-orange-600 dark:bg-orange-400" />{{ t('groups.activities.overview.board.composition.extra') }}</span>
            </div>
            <AllianceProgressLegend v-else-if="allianceProgressEnabled" :loading="progression.loading.value" :failed="progression.failed.value"
                :fflogs-unavailable="progression.fflogsUnavailable.value" @retry="progression.reload()" />
        </header>

        <slot :plainDpsEnabled="plainDpsEnabled" :numberedSecondaryPartiesEnabled="numberedSecondaryPartiesEnabled"
            :progressStatus="progressStatus" :compositionTone="compositionTone" />
        <section v-if="selectedPreset && missingGroups.length" aria-labelledby="roster-composition-missing"
            class="m-3 space-y-3 rounded-xl border border-error/25 bg-error/5 p-3 sm:m-4 sm:p-4">
            <h3 id="roster-composition-missing" class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-circle-alert" class="size-4 text-error" aria-hidden="true" />
                {{ t('groups.activities.overview.board.composition.missing') }}
            </h3>
            <div v-for="group in missingGroups" :key="group.key">
                <h4 class="mb-1.5 text-xs font-medium text-toned">{{ group.label }}</h4>
                <ul class="flex flex-wrap gap-2">
                    <li v-for="row in group.requirements" :key="row.key" :title="row.comparisonLabel"
                        class="inline-flex items-center gap-2 rounded-lg bg-elevation-1 px-2.5 py-1.5 text-xs text-toned shadow-sm dark:bg-elevation-4">
                        <img v-if="row.itemIconUrl" :src="row.itemIconUrl" alt="" class="size-4 object-contain" />
                        {{ row.itemLabel }}
                        <UBadge color="error" variant="soft" size="sm" class="rounded-full tabular-nums" :label="`${row.currentCount}/${row.targetCount}`" />
                    </li>
                </ul>
            </div>
        </section>
        <RosterDesignationLegend :slots="activity.slots" :show-self="showSelf" />
    </section>

    <section v-for="card in reserveCards" :key="card.kind" :aria-label="card.title" class="mt-5" :class="cardClass">
        <header class="flex items-center gap-3 border-b border-default px-4 py-3 sm:px-6">
            <h2 class="flex items-center gap-2 font-semibold sm:gap-3 sm:text-lg">
                <UIcon :name="card.icon" class="size-5 shrink-0 text-muted sm:size-6" aria-hidden="true" />
                {{ card.title }}
            </h2>
            <UBadge v-if="card.slots.length" color="neutral" variant="soft" class="rounded-full tabular-nums"
                :label="t('groups.activities.overview.roster_capacity', { assigned: card.filled, total: card.slots.length })" />
        </header>
        <div v-if="card.slots.length" class="grid auto-rows-fr gap-2.5 p-2.5 md:grid-cols-2 xl:grid-cols-4">
            <template v-for="reserveSlot in card.slots" :key="reserveSlot.id">
                <slot v-if="card.kind === 'bench' && $slots.bench" name="bench" :slot="reserveSlot"
                    :progressStatus="progressStatus(reserveSlot)" :roleHighlights="!plainDpsEnabled" :compositionTone="compositionTone(reserveSlot)" />
                <ActivityAttendeeRosterSlot v-else :slot="reserveSlot" :progress-status="progressStatus(reserveSlot)"
                    :role-highlights="!plainDpsEnabled" :composition-tone="compositionTone(reserveSlot)" />
            </template>
        </div>
        <p v-else class="px-4 py-3 text-sm text-muted sm:px-6">{{ card.empty }}</p>
    </section>
</template>
