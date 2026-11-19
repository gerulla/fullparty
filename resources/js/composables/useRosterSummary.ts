import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePage } from '@inertiajs/vue3'
import type { ActivityRosterSummaryPreset, ActivityRosterSummaryRequirement, ActivityRosterSummaryRequirementRow, ActivitySlot } from '@/Types/ActivityRoster'
import type { LocalizedText } from '@/Types/Common'
import { displayActivityPartyLabel } from '@/utils/activityPartyLabels'
import { translateCharacterClassName, translatePhantomJobName } from '@/utils/characterJobTranslations'
import { localizedValue } from '@/utils/localizedValue'
import { matchRosterRequirements } from '@/utils/rosterComposition'

export function useRosterSummary(preset: () => ActivityRosterSummaryPreset | null, slots: () => ActivitySlot[], numberedParties: () => boolean) {
    const { t, locale } = useI18n()
    const page = usePage()
    const localizedText = (value: LocalizedText, fallback: string) =>
        localizedValue(value, locale.value, String(page.props.locale?.fallback ?? 'en')) || fallback

    function scopeLabel(requirement: ActivityRosterSummaryRequirement) {
        if (requirement.scope_type === 'all_slots') return t('groups.activities.management.overview.roster_summary.scope_all')
        const groups = requirement.scope_type === 'slot_group' ? requirement.scope_groups.slice(0, 1) : requirement.scope_groups
        return groups.map(group => displayActivityPartyLabel(group.key, localizedText(group.label, group.key),
            numberedParties(), t('groups.activities.overview.board.party_label'))).join(' / ')
            || t('groups.activities.management.overview.roster_summary.scope_unknown')
    }

    function itemLabel(requirement: ActivityRosterSummaryRequirement) {
        const name = localizedText(requirement.item.label, String(requirement.source_id))
        if (requirement.source === 'phantom_jobs') return translatePhantomJobName(t, { name }, name)
        if (requirement.source === 'character_classes') {
            return translateCharacterClassName(t, { name, shorthand: requirement.item.meta?.shorthand }, name)
        }
        return name
    }

    const matches = computed(() => matchRosterRequirements(preset()?.requirements ?? [], slots()))
    const requirementRows = computed<ActivityRosterSummaryRequirementRow[]>(() => matches.value.map(({ requirement, slotIds, missing, overLimit }, index) => {
        const { comparison, target_count: targetCount } = requirement
        const currentCount = slotIds.length
        const color = missing ? 'error' : overLimit ? 'warning' : 'success'
        const meta = requirement.item.meta
        return {
            key: `${preset()?.key}-${index}`,
            scopeKey: `${requirement.scope_type}:${requirement.scope_group_keys.join('|')}`,
            scopeLabel: scopeLabel(requirement),
            itemLabel: itemLabel(requirement),
            itemIconUrl: meta?.transparent_icon_url || meta?.flaticon_url || meta?.icon_url || meta?.sprite_url || null,
            currentCount, targetCount,
            comparisonLabel: t(`groups.activities.management.overview.roster_summary.comparisons.${comparison}`),
            comparisonShortLabel: { at_least: '>=', exactly: '=', at_most: '<=' }[comparison],
            state: { color, badgeVariant: 'soft', toneClass: {
                success: 'border-success/30 bg-success/10', error: 'border-error/30 bg-error/10', warning: 'border-warning/30 bg-warning/10',
            }[color] },
        }
    }))

    const groupedRequirementRows = computed(() => groupRows(requirementRows.value))
    const missingGroups = computed(() => groupRows(requirementRows.value.filter(row => row.state.color === 'error')))
    return { matches, localizedText, requirementRows, groupedRequirementRows, missingGroups }
}

function groupRows(rows: ActivityRosterSummaryRequirementRow[]) {
    const groups = new Map<string, { key: string; label: string; requirements: ActivityRosterSummaryRequirementRow[] }>()
    for (const row of rows) {
        const group = groups.get(row.scopeKey)
        if (group) group.requirements.push(row)
        else groups.set(row.scopeKey, { key: row.scopeKey, label: row.scopeLabel, requirements: [row] })
    }
    return [...groups.values()]
}
