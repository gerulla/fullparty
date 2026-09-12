import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'

function load(name, modules) {
    const source = readFileSync(new URL(`../../resources/js/composables/${name}.ts`, import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', code)(id => { assert.ok(id in modules, id); return modules[id] }, exports)
    return exports
}

function harness() {
    const calls = []
    let finish, fail
    const settings = vue.ref({ collection_id: 2, active_count: 1, resources: [{ id: -7, title: 'Tank', holster_id: 7 }] })
    const { useResourceHolsters } = load('useResourceHolsters', {
        vue, 'vue-i18n': { useI18n: () => ({ t: key => key }) },
        'ziggy-js': { route: (name, params) => `${name}/${params.group}` },
        axios: { default: { isAxiosError: error => !!error.response, put: (...args) => {
            calls.push(args)
            return new Promise((resolve, reject) => { finish = data => resolve({ data: { data } }); fail = reject })
        } } },
    })
    const scope = vue.effectScope()
    const api = scope.run(() => useResourceHolsters(() => 'our-group', () => settings.value))
    return { api, settings, calls, finish: data => finish(data), fail: error => fail(error), stop: () => scope.stop() }
}

test('holster settings preserve the saved listing until a single successful save replaces it', async () => {
    const h = harness()
    h.api.open()
    h.api.state.collectionId = '4'
    const save = h.api.save()
    await h.api.save()
    assert.equal(h.calls.length, 1)
    assert.deepEqual(h.calls[0], ['groups.dashboard.resources.library.holsters.update/our-group', { collection_id: 4 }])
    assert.equal(h.api.state.data.collection_id, 2)
    assert.equal(h.api.state.busy, true)
    h.finish({ ...h.settings.value, collection_id: 4 })
    await save
    assert.equal(h.api.state.data.collection_id, 4)
    assert.equal(h.api.state.open, false)
    assert.equal(h.api.state.busy, false)
    h.api.open()
    assert.equal(h.api.state.collectionId, '4')
    h.stop()
})

test('removing the holster listing sends null and clears only linked rows', async () => {
    const h = harness()
    h.api.open(); h.api.state.collectionId = 'disabled'
    const save = h.api.save()
    assert.deepEqual(h.calls[0][1], { collection_id: null })
    h.finish({ collection_id: null, active_count: 1, resources: [] }); await save
    assert.equal(h.api.state.data.active_count, 1)
    assert.deepEqual(h.api.state.data.resources, [])
    h.stop()
})

test('failed holster settings keep the modal and existing listing available for retry', async () => {
    const h = harness()
    h.api.open(); h.api.state.collectionId = '4'
    const save = h.api.save()
    h.fail({ response: { status: 422, data: { errors: { collection_id: ['Collection no longer exists.'] } } } }); await save
    assert.equal(h.api.state.error, 'Collection no longer exists.')
    assert.equal(h.api.state.open, true)
    assert.equal(h.api.state.data.collection_id, 2)
    assert.equal(h.api.state.collectionId, '4')
    assert.equal(h.api.state.busy, false)
    h.stop()
})

test('holster reader links use the dedicated route on public and group pages', () => {
    const { useResourceReader } = load('useResourceReader', {
        vue, '@inertiajs/vue3': { router: {} }, 'vue-i18n': { useI18n: () => ({ locale: vue.ref('en'), t: key => key }) },
        'ziggy-js': { route: (name, params) => ({ name, params }) },
        '@/utils/resourceReader': { readerCollectionPath: () => [], readerCollectionTree: () => [] },
        '@/utils/localizedValue': { localizedValue: value => value.en },
    })
    for (const publicView of [true, false]) {
        const scope = vue.effectScope()
        const hub = scope.run(() => useResourceReader(() => ({ group: { slug: 'our-group' }, filters: {} }), publicView))
        const url = hub.navigation.resource({ id: -7, holster_id: 7, source_type: 'holster', slug: '7', is_home: false })
        assert.equal(url.name, `${publicView ? 'public-resources' : 'groups.dashboard.resources'}.holsters.show`)
        assert.equal(url.params.holster, 7)
        assert.equal(url.params.group, 'our-group')
        scope.stop()
    }
})
