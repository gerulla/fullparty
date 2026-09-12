import { computed, ref } from 'vue'
import type { HolsterPairOption, HolsterPairValue } from '@/Types/ActivityHolsters'
import { filterHolsterPlannerGroups, holsterPairKey, holsterPlannerGroups, normalizeHolsterPairs } from '@/utils/holsterPlanner'

export function useHolsterPairPlanner(
    props: { modelValue: unknown; options: HolsterPairOption[]; multiple?: boolean; disabled?: boolean; allowedPairs?: HolsterPairValue[] },
    locale: () => string,
    fallback: () => string,
    update: (value: HolsterPairValue | HolsterPairValue[]) => void,
) {
    const open = ref(false)
    const query = ref('')
    const draft = ref<HolsterPairValue[]>([])
    const groups = computed(() => holsterPlannerGroups(props.options, locale(), fallback(), props.allowedPairs))
    const filteredGroups = computed(() => filterHolsterPlannerGroups(groups.value, query.value))
    const selected = computed(() => normalizeHolsterPairs(props.modelValue, Boolean(props.multiple)))
    const validKeys = computed(() => new Set(groups.value.flatMap(group => group.standalone
        ? [holsterPairKey({ prepop_id: String(group.prepop.id), refill_id: '' })]
        : group.refills.map(refill => holsterPairKey({ prepop_id: String(group.prepop.id), refill_id: String(refill.id) })))))
    const validDraft = computed(() => draft.value.filter(pair => validKeys.value.has(holsterPairKey(pair))))
    const selectedLoadouts = computed(() => selected.value.map(pair => {
        const group = groups.value.find(group => String(group.prepop.id) === pair.prepop_id)
        return { pair, available: validKeys.value.has(holsterPairKey(pair)), prepop: group?.prepop, refill: group?.refills.find(refill => String(refill.id) === pair.refill_id) }
    }))
    const emitPairs = (pairs: HolsterPairValue[]) => update(props.multiple ? pairs : (pairs[0] ?? { prepop_id: '', refill_id: '' }))

    function show() {
        if (props.disabled) return
        query.value = ''
        draft.value = selected.value.filter(pair => validKeys.value.has(holsterPairKey(pair))).map(pair => ({ ...pair }))
        open.value = true
    }
    function toggle(pair: HolsterPairValue) {
        if (props.disabled || !validKeys.value.has(holsterPairKey(pair))) return
        const present = validDraft.value.some(value => holsterPairKey(value) === holsterPairKey(pair))
        draft.value = present ? validDraft.value.filter(value => holsterPairKey(value) !== holsterPairKey(pair))
            : props.multiple ? [...validDraft.value, pair] : [pair]
    }
    function confirm() {
        if (props.disabled) return
        emitPairs(validDraft.value.map(pair => ({ ...pair })))
        open.value = false
    }
    function remove(pair: HolsterPairValue) {
        if (!props.disabled) emitPairs(selected.value.filter(value => holsterPairKey(value) !== holsterPairKey(pair)))
    }
    return { open, query, groups, filteredGroups, validDraft, selectedLoadouts, show, toggle, confirm, remove }
}
