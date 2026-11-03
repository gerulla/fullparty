import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'

const dom = new JSDOM('<html><body></body></html>', { url: 'https://fullparty.test/en/dashboard/runs' })
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node']) globalThis[key] = dom.window[key]
const vue = await import('vue')
const filename = new URL('../../resources/js/components/Runs/RunDiscoveryFilterPanel.vue', import.meta.url).pathname
const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename })
const code = ts.transpileModule(compileScript(descriptor, { id: filename }).content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText
const modules = {
    vue,
    'vue-i18n': { useI18n: () => ({ t: key => key, locale: vue.ref('en') }) },
    '@/components/Runs/RunDiscoveryClassPickerModal.vue': { default: {} },
    '@/composables/useTimeDisplayMode': { useTimeDisplayMode: () => ({ displayTimeZone: vue.ref('UTC'), displayTimeZoneLabel: vue.ref('Server Time') }) },
}
const exports = {}
new Function('require', 'exports', code)(name => {
    assert.ok(name in modules, name)
    return modules[name]
}, exports)
// Exercise the real mounted filter state and emitted request payload without rendering Nuxt UI controls.
const FilterPanel = { ...exports.default, render: () => null }
const lookups = {
    activity_types: [
        { id: 10, value: 'forked-tower', label: 'The Forked Tower', difficulty: 'chaotic', prog_points: [] },
        { id: 20, value: 'savage', label: 'Savage', difficulty: 'savage', prog_points: [] },
    ],
    groups: [
        { value: 'storm', label: 'Storm Keepers', group_type: 'community' },
        { value: 'static-team', label: 'Static Team', group_type: 'static' },
    ],
    class_options: [], datacenters: [], regions: [], languages: [], run_styles: [], intensities: [], voice_expectations: [],
}

for (const [query, expected, expectedGroup = 'any', expectedGroupType = 'community'] of [
    ['?activity_type_id=20', 'savage'],
    ['?activity_type_id=10&activity_type=savage', 'forked-tower'],
    ['?activity_type=savage', 'savage'],
    ['?activity_type_id=999', 'any'],
    ['?activity_type_id=20junk', 'any'],
    ['?activity_type_id[]=20', 'any'],
    ['?activity_type_id=10&group=storm', 'forked-tower', 'storm'],
    ['?activity_type_id=20&group=static-team', 'savage', 'static-team', 'static'],
    ['?activity_type_id=20&group=unavailable', 'savage'],
    ['', 'any'],
]) {
    test(`discovery prefills its first filter payload from ${query || 'an unfiltered URL'}`, async t => {
        window.history.replaceState({}, '', `/en/dashboard/runs${query}`)
        const emitted = []
        const root = document.body.appendChild(document.createElement('div'))
        const app = vue.createApp(FilterPanel, { lookups, onFiltersChange: filters => emitted.push(filters) })
        const vm = app.mount(root)
        t.after(() => { app.unmount(); root.remove() })
        await vue.nextTick()
        assert.ok(emitted.length > 0)
        assert.ok(emitted.every(filters => filters.activity_type === expected))
        assert.ok(emitted.every(filters => filters.group === expectedGroup && filters.group_type === expectedGroupType))
        assert.equal(vm.$.setupState.selectedActivityType, expected)
        assert.equal(vm.$.setupState.selectedGroup, expectedGroup)
        vm.$.setupState.resetFilters()
        await vue.nextTick()
        assert.equal(vm.$.setupState.selectedActivityType, 'any')
        assert.equal(emitted.at(-1).activity_type, 'any')
        assert.equal(emitted.at(-1).group, 'any')
    })
}
