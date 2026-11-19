import assert from 'node:assert/strict'
import { test } from 'node:test'
import { compositionSlotTones, matchRosterRequirements } from '../../resources/js/utils/rosterComposition.ts'

const bard = { source: 'phantom_jobs', source_id: 7, comparison: 'at_least', target_count: 1, scope_type: 'all_slots', scope_group_keys: [] }
const slot = (id, group_key, value, overrides = {}) => ({
    id, group_key, sort_order: id, assigned_character_id: id, field_values: [{ source: 'phantom_jobs', value }], ...overrides,
})

test('counts occupied main slots once per scoped rule and marks extra matches in roster order', () => {
    const slots = [slot(3, 'B', 7), slot(2, 'A', ['7', { key: '7' }]), slot(1, 'A', { id: 7 }),
        slot(4, 'B', 7, { is_bench: true }), slot(5, 'B', 7, { is_fill_in: true }), slot(6, 'B', 7, { assigned_character_id: null }),
        slot(7, 'B', 7, { field_values: [{ source: 'character_classes', value: 7 }] })]
    const requirements = [
        { ...bard, scope_type: 'slot_group', scope_group_keys: ['A'] },
        { ...bard, scope_type: 'slot_group', scope_group_keys: ['B'], comparison: 'exactly', target_count: 2 },
        { ...bard, scope_type: 'slot_group_set', scope_group_keys: ['B', 'C'], target_count: 1 },
        { ...bard, source_id: 42, comparison: 'at_most' },
    ]
    const matches = matchRosterRequirements(requirements, slots)
    assert.deepEqual(matches.map(match => match.slotIds), [[1, 2], [3], [3], []])
    assert.deepEqual(matches.filter(match => match.missing).map(match => match.requirement), [requirements[1]])
    assert.deepEqual(Object.fromEntries(compositionSlotTones(matches)), { 1: 'matched', 2: 'extra', 3: 'matched' })
})

test('a needed party match wins over a surplus minimum, and an explicit upper limit still flags excess', () => {
    const slots = [slot(1, 'A', 7), slot(2, 'B', 7)]
    const partyB = { ...bard, scope_type: 'slot_group', scope_group_keys: ['B'] }
    const cap = { ...bard, comparison: 'at_most' }
    for (const rules of [[bard, partyB], [partyB, bard]]) {
        assert.equal(compositionSlotTones(matchRosterRequirements(rules, slots)).get(2), 'matched')
        const withCap = matchRosterRequirements([cap, ...rules], slots)
        assert.equal(compositionSlotTones(withCap).get(2), 'extra')
        assert.equal(withCap[0].overLimit, true)
        assert.ok(withCap.every(match => !match.missing))
    }
})
