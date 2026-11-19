import type { ActivityRosterSummaryRequirement, ActivitySlot, RosterCompositionTone } from '@/Types/ActivityRoster'

export const compositionSlotColors: Record<RosterCompositionTone, string> = {
    neutral: 'border-elevation-5 bg-elevation-2 dark:border-elevation-6 dark:bg-elevation-4',
    matched: 'border-green-600/60 bg-green-500/15 dark:border-green-400/65 dark:bg-green-400/15',
    extra: 'border-orange-600/65 bg-orange-500/15 dark:border-orange-400/70 dark:bg-orange-400/15',
}

function fieldMatches(value: unknown, expected: string): boolean {
    if (Array.isArray(value)) return value.some(entry => fieldMatches(entry, expected))
    if (value && typeof value === 'object') {
        const record = value as Record<string, unknown>
        value = record.id ?? record.key
    }
    return value != null && value !== '' && String(value) === expected
}

export function matchRosterRequirements(requirements: ActivityRosterSummaryRequirement[], slots: ActivitySlot[]) {
    if (!requirements.length) return []
    const main = slots.filter(slot => !slot.is_bench && !slot.is_fill_in && slot.assigned_character_id !== null)
        .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)

    return requirements.map(requirement => {
        const slotIds = main.filter(slot =>
            (requirement.scope_type === 'all_slots' || requirement.scope_group_keys.includes(slot.group_key))
            && slot.field_values.some(field => field.source === requirement.source && fieldMatches(field.value, String(requirement.source_id)))
        ).map(slot => slot.id)
        const missing = requirement.comparison !== 'at_most' && slotIds.length < requirement.target_count
        const overLimit = requirement.comparison !== 'at_least' && slotIds.length > requirement.target_count
        return { requirement, slotIds, missing, overLimit }
    })
}

export function compositionSlotTones(matches: ReturnType<typeof matchRosterRequirements>) {
    const tones = new Map<number, RosterCompositionTone>()
    const overLimit = new Set<number>()
    for (const { requirement, slotIds } of matches) {
        slotIds.forEach((id, index) => {
            if (index < requirement.target_count) {
                if (requirement.comparison !== 'at_most') tones.set(id, 'matched')
            } else {
                // An extra for a minimum can still be needed by an overlapping party rule.
                if (!tones.has(id)) tones.set(id, 'extra')
                if (requirement.comparison !== 'at_least') overLimit.add(id)
            }
        })
    }
    for (const id of overLimit) tones.set(id, 'extra')
    return tones
}
