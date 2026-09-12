import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import ts from 'typescript'
import * as vue from 'vue'

function load(file, modules = {}) {
    const source = readFileSync(new URL(`../../resources/js/${file}.ts`, import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name] }, exports)
    return exports
}
const localized = load('utils/localizedValue')
const utils = load('utils/holsterPlanner', { './localizedValue': localized })
const { useHolsterPairPlanner } = load('composables/useHolsterPairPlanner', { vue, '@/utils/holsterPlanner': utils })
const item = { key: '8', label: { en: 'Reraiser', ja: 'リレイザー' }, quantity: 2, cache_weight: 8 }
const option = (key, type, parent, label, extra = {}) => ({ key, label: { en: label, ja: `${label}日本語` }, meta: { holster_type: type, parent_holster_id: parent, items: [item], ...extra } })
const options = [option('1', 'prepop', null, 'Starter'), option('2', 'refill', 1, 'Damage', { notes: 'Burst supplies', capacity_used: 16, max_capacity: 99 }), option('3', 'refill', 1, 'Recovery'), option('4', 'prepop', null, 'Tank'), option('5', 'refill', 4, 'Defense'), option('6', 'refill', 99, 'Orphan')]
const pair = (prepop = '1', refill = '2') => ({ prepop_id: prepop, refill_id: refill })
function harness(overrides = {}) {
    const props = vue.reactive({ modelValue: [pair()], options: structuredClone(options), multiple: true, ...overrides })
    const emitted = []
    const scope = vue.effectScope()
    const api = scope.run(() => useHolsterPairPlanner(props, () => 'en', () => 'en', value => { emitted.push(value); props.modelValue = value }))
    return { props, api, emitted, stop: () => scope.stop() }
}

test('groups localize complete loadouts and enforce exact allowed pairs', () => {
    const groups = utils.holsterPlannerGroups(options, 'ja', 'en', [pair()])
    assert.equal(groups.length, 1)
    assert.equal(groups[0].prepop.name, 'Starter日本語')
    assert.deepEqual(groups[0].refills.map(h => h.id), ['2'])
    assert.equal(groups[0].refills[0].items[0].name, 'リレイザー')
    assert.equal(groups[0].refills[0].capacity_used, 16)
    assert.equal(groups[0].refills[0].notes, 'Burst supplies')
    assert.deepEqual(utils.holsterPlannerGroups(options, 'en', 'en', []), [])
})

test('search keeps the parent visible while matching refill names notes or items', () => {
    const groups = utils.holsterPlannerGroups(options, 'en', 'en')
    assert.equal(utils.filterHolsterPlannerGroups(groups, 'burst')[0].prepop.id, '1')
    assert.deepEqual(utils.filterHolsterPlannerGroups(groups, 'burst')[0].refills.map(h => h.id), ['2'])
    assert.equal(utils.filterHolsterPlannerGroups(groups, 'STARTER')[0].refills.length, 2)
    assert.equal(utils.filterHolsterPlannerGroups(groups, 'reraiser').length, 2)
    assert.equal(utils.filterHolsterPlannerGroups(groups, 'missing').length, 0)
})

test('multiple selections remain draft until confirmed and cancelling discards them', () => {
    const h = harness(); h.api.show(); h.api.toggle(pair('1', '3'))
    assert.equal(h.api.validDraft.value.length, 2)
    assert.equal(h.emitted.length, 0)
    h.api.open.value = false; h.api.show()
    assert.deepEqual(h.api.validDraft.value, [pair()])
    h.api.toggle(pair('1', '3')); h.api.query.value = 'Tank'
    assert.equal(h.api.validDraft.value.length, 2)
    h.api.confirm()
    assert.deepEqual(h.props.modelValue, [pair(), pair('1', '3')])
    h.api.remove(pair()); assert.deepEqual(h.props.modelValue, [pair('1', '3')])
    h.stop()
})

test('single selection replaces the previous pair and preserves the scalar payload shape', () => {
    const h = harness({ multiple: false, modelValue: pair() })
    h.api.show(); h.api.toggle(pair('1', '3')); h.api.confirm()
    assert.deepEqual(h.props.modelValue, pair('1', '3'))
    h.api.remove(pair('1', '3')); assert.deepEqual(h.props.modelValue, { prepop_id: '', refill_id: '' })
    h.stop()
})

test('invalid pairs cannot be selected and changed permissions are rechecked at confirmation', () => {
    const h = harness(); h.api.show(); h.api.toggle(pair('1', '5'))
    assert.deepEqual(h.api.validDraft.value, [pair()])
    h.api.toggle(pair('1', '3')); h.props.allowedPairs = [pair('1', '3')]
    h.api.confirm(); assert.deepEqual(h.props.modelValue, [pair('1', '3')])
    h.stop()
})

test('disabled controls cannot change applied or draft selections', () => {
    const h = harness({ disabled: true }); h.api.show(); h.api.toggle(pair('1', '3')); h.api.remove(pair()); h.api.confirm()
    assert.equal(h.api.open.value, false)
    assert.deepEqual(h.emitted, [])
    h.stop()
})

test('missing stored options remain removable and duplicate pairs normalize once', () => {
    const h = harness({ modelValue: [pair('9', '10'), pair('9', '10')] })
    assert.equal(h.api.selectedLoadouts.value.length, 1)
    assert.equal(h.api.selectedLoadouts.value[0].prepop, undefined)
    h.api.remove(pair('9', '10')); assert.deepEqual(h.props.modelValue, [])
    h.stop()
})

test('standalone pre-pops are searchable selectable and retained after loading a null refill', () => {
    const solo = option('7', 'prepop', null, 'Solo healer', { notes: 'Independent supplies' })
    const h = harness({ options: [...options, solo], modelValue: [{ prepop_id: 7, refill_id: null }] })
    assert.deepEqual(h.api.selectedLoadouts.value[0].pair, pair('7', ''))
    assert.equal(h.api.selectedLoadouts.value[0].available, true)
    h.api.show(); h.api.query.value = 'independent'
    assert.equal(h.api.filteredGroups.value.length, 1)
    assert.equal(h.api.filteredGroups.value[0].standalone, true)
    h.api.toggle(pair()); h.api.confirm()
    assert.deepEqual(h.props.modelValue, [pair('7', ''), pair()])
    assert.ok(utils.availableHolsterPairs(h.props.options).some(value => utils.holsterPairKey(value) === '7:'))
    h.stop()
})

test('filtering out refills never turns a paired pre-pop into a standalone choice', () => {
    assert.deepEqual(utils.holsterPlannerGroups(options, 'en', 'en', [pair('1', '')]), [])
    const groups = utils.holsterPlannerGroups(options, 'en', 'en')
    assert.deepEqual(utils.filterHolsterPlannerGroups(groups, 'missing refill'), [])
    const h = harness(); h.api.show(); h.api.toggle(pair('1', ''))
    assert.deepEqual(h.api.validDraft.value, [pair()])
    h.stop()
})

test('a standalone choice is invalidated if an active refill becomes available', () => {
    const h = harness({ options: [option('7', 'prepop', null, 'Solo')], modelValue: [pair('7', '')] })
    h.api.show()
    h.props.options.push(option('8', 'refill', 7, 'New refill'))
    assert.equal(h.api.selectedLoadouts.value[0].available, false)
    h.api.confirm(); assert.deepEqual(h.props.modelValue, [])
    h.stop()
})
