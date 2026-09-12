import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'
import { parse, compileScript } from '@vue/compiler-sfc'
import { addCollection, getIcon } from '@iconify/vue'
import catalog from '@iconify-json/lucide/icons.json' with { type: 'json' }

function picker() {
    const { descriptor } = parse(readFileSync(new URL('../../resources/js/components/Groups/Resources/ResourceCollectionIconModal.vue', import.meta.url), 'utf8'))
    const source = compileScript(descriptor, { id: 'icons' }).content
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = { vue, 'vue-i18n': { useI18n: () => ({ t: key => key }) }, '@iconify/vue': { addCollection }, '@iconify-json/lucide/icons.json': { default: catalog } }
    const exports = {}
    new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name] }, exports)
    return exports.default.setup({ name: 'Guides', icon: 'i-lucide-swords', busy: false, error: '' }, { expose() {}, emit() {} })
}

test('the picker exposes every bundled Lucide icon with local SVG data and bounded pages', () => {
    const vm = picker()
    assert.deepEqual(vm.names, Object.keys(catalog.icons).sort())
    assert.ok(vm.names.length > 1500)
    const visited = []
    for (let page = 1; page <= Math.ceil(vm.names.length / vm.pageSize); page++) {
        vm.page.value = page
        assert.ok(vm.visible.value.length <= vm.pageSize)
        visited.push(...vm.visible.value)
    }
    assert.deepEqual(visited, vm.names)
    for (const name of visited) assert.ok(getIcon(`lucide:${name}`)?.body, name)
})

test('icon searches support the saved i-lucide name, ordinary words and empty results', async () => {
    const vm = picker()
    vm.page.value = 8
    vm.query.value = ' I-LUCIDE-SWORDS '
    await vue.nextTick()
    assert.equal(vm.page.value, 1)
    assert.deepEqual(vm.filtered.value, ['swords'])
    vm.query.value = 'arrow up'
    assert.ok(vm.filtered.value.includes('arrow-up'))
    vm.query.value = 'no-such-icon-123'
    assert.deepEqual(vm.visible.value, [])
})
