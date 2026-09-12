import type { HolsterPairOption, HolsterPairValue } from '../Types/ActivityHolsters'
import type { HolsterLoadout, HolsterPlannerGroup } from '../Types/HolsterPlanner'
import { localizedValue } from './localizedValue'

export const holsterPairKey = (pair: HolsterPairValue) => `${pair.prepop_id}:${pair.refill_id}`

export function normalizeHolsterPairs(value: unknown, multiple: boolean): HolsterPairValue[] {
    const values = multiple ? (Array.isArray(value) ? value : []) : [value]
    const pairs = new Map<string, HolsterPairValue>()
    for (const entry of values) {
        if (!entry || typeof entry !== 'object' || Array.isArray(entry)) continue
        const pair = entry as Record<string, unknown>
        if (!pair.prepop_id) continue
        const normalized = { prepop_id: String(pair.prepop_id), refill_id: String(pair.refill_id ?? '') }
        pairs.set(holsterPairKey(normalized), normalized)
    }
    return [...pairs.values()]
}

export function availableHolsterPairs(options: HolsterPairOption[]): HolsterPairValue[] {
    return options.filter(option => option.meta?.holster_type === 'prepop').flatMap(prepop => {
        const refills = options.filter(option => option.meta?.holster_type === 'refill'
            && String(option.meta.parent_holster_id ?? '') === prepop.key)
        return refills.length ? refills.map(refill => ({ prepop_id: prepop.key, refill_id: refill.key }))
            : [{ prepop_id: prepop.key, refill_id: '' }]
    })
}

export function holsterPlannerGroups(options: HolsterPairOption[], locale: string, fallback: string, allowedPairs?: HolsterPairValue[]): HolsterPlannerGroup[] {
    const allowed = allowedPairs ? new Set(allowedPairs.map(holsterPairKey)) : null
    const loadout = (option: HolsterPairOption): HolsterLoadout => ({
        id: option.key,
        name: localizedValue(option.label, locale, fallback) || option.key,
        type: option.meta?.holster_type === 'refill' ? 'refill' : 'prepop',
        role: option.meta?.role ?? null,
        notes: option.meta?.notes ?? null,
        capacity_used: option.meta?.capacity_used ?? null,
        max_capacity: option.meta?.max_capacity ?? null,
        items: (option.meta?.items ?? []).map(item => ({
            id: item.key, name: localizedValue(item.label, locale, fallback) || item.key,
            icon_url: item.icon_url ?? null, quantity: item.quantity, cache_weight: item.cache_weight,
        })),
    })
    return options.filter(option => option.meta?.holster_type === 'prepop').map(prepop => {
        const refills = options.filter(refill => refill.meta?.holster_type === 'refill'
            && String(refill.meta.parent_holster_id ?? '') === prepop.key)
        return {
            prepop: loadout(prepop),
            standalone: refills.length === 0 && (!allowed || allowed.has(holsterPairKey({ prepop_id: prepop.key, refill_id: '' }))),
            refills: refills.filter(refill => !allowed || allowed.has(holsterPairKey({ prepop_id: prepop.key, refill_id: refill.key }))).map(loadout),
        }
    }).filter(group => group.standalone || group.refills.length > 0)
}

export function filterHolsterPlannerGroups(groups: HolsterPlannerGroup[], query: string): HolsterPlannerGroup[] {
    const search = query.trim().toLocaleLowerCase()
    if (!search) return groups
    const matches = (holster: HolsterLoadout) => [holster.name, holster.notes, holster.role, holster.type, ...holster.items.map(item => item.name)]
        .filter(Boolean).join(' ').toLocaleLowerCase().includes(search)
    return groups.map(group => ({ ...group, standalone: group.standalone && matches(group.prepop), refills: matches(group.prepop) ? group.refills : group.refills.filter(matches) }))
        .filter(group => group.standalone || group.refills.length > 0)
}
