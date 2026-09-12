import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import ts from 'typescript'
import * as vue from 'vue'

function harness(overrides = {}) {
    const requests = []
    const cleanups = []
    const source = readFileSync(new URL('../../resources/js/composables/useAllianceProgress.ts', import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = {
        vue: { ...vue, onBeforeUnmount: callback => cleanups.push(callback) },
        'ziggy-js': { route: (_, params) => `${params.group}/${params.activity}` },
        axios: { default: { isCancel: error => error?.cancelled, get: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })) } },
    }
    const exports = {}
    new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name] }, exports)
    const context = vue.reactive({ enabled: false, groupSlug: 'group', activityId: 1, targetProgPointKey: 'second', slots: [{ assigned_character_id: 1 }], ...overrides })
    const scope = vue.effectScope()
    const api = scope.run(() => exports.useAllianceProgress(context))
    return { context, api, requests, close: () => { cleanups.forEach(callback => callback()); scope.stop() } }
}
const settle = async () => { for (let count = 0; count < 5; count++) await Promise.resolve(); await vue.nextTick() }
const response = (ids, status = 'at_target', unavailable = false) => ({ data: { characters: Object.fromEntries(ids.map(id => [id, { status, fflogs_unavailable: unavailable }])) } })

test('loads only after enabling and reuses results when toggling back on', async () => {
    const h = harness()
    assert.equal(h.requests.length, 0)
    h.context.enabled = true; await settle()
    h.requests[0].resolve(response([1])); await settle()
    assert.equal(h.api.records.value[1].status, 'at_target')
    h.context.enabled = false; await settle()
    h.context.enabled = true; await settle()
    assert.equal(h.requests.length, 1)
    h.close()
})

test('deduplicates characters and loads a large roster in bounded batches', async () => {
    const h = harness({ enabled: true, slots: [...Array.from({ length: 12 }, (_, index) => ({ assigned_character_id: index + 1 })), { assigned_character_id: 1 }, { assigned_character_id: null }] })
    assert.equal(h.requests.length, 2)
    assert.deepEqual(h.requests[0].options.params.character_ids, [1, 2, 3, 4])
    assert.deepEqual(h.requests[1].options.params.character_ids, [5, 6, 7, 8])
    h.requests[0].resolve(response([1, 2, 3, 4])); await settle()
    assert.equal(h.requests.length, 3)
    assert.deepEqual(h.requests[2].options.params.character_ids, [9, 10, 11, 12])
    h.requests[1].resolve(response([5, 6, 7, 8])); h.requests[2].resolve(response([9, 10, 11, 12])); await settle()
    assert.equal(h.api.loading.value, false)
    assert.equal(Object.keys(h.api.records.value).length, 12)
    h.close()
})

test('aborts and ignores stale responses after changing runs or disabling', async () => {
    const h = harness({ enabled: true })
    h.context.activityId = 2; await settle()
    assert.equal(h.requests[0].options.signal.aborted, true)
    h.requests[0].resolve(response([1], 'cleared')); await settle()
    assert.deepEqual(h.api.records.value, {})
    h.context.enabled = false; await settle()
    assert.equal(h.requests[1].options.signal.aborted, true)
    h.requests[1].resolve(response([1], 'cleared')); await settle()
    assert.deepEqual(h.api.records.value, {})
    h.close()
})

test('retains available progress and retries failed batches without refetching successful ones', async () => {
    const h = harness({ enabled: true, slots: Array.from({ length: 5 }, (_, index) => ({ assigned_character_id: index + 1 })) })
    h.requests[0].resolve(response([1, 2, 3, 4])); h.requests[1].reject(new Error('Offline')); await settle()
    assert.equal(h.api.failed.value, true)
    assert.equal(h.api.records.value[5], undefined)
    void h.api.reload(); await settle()
    assert.deepEqual(h.requests[2].options.params.character_ids, [5])
    h.requests[2].resolve(response([5], 'below_target', true)); await settle()
    assert.equal(h.api.failed.value, false)
    assert.equal(h.api.fflogsUnavailable.value, true)
    void h.api.reload(); await settle()
    assert.deepEqual(h.requests[3].options.params.character_ids, [5])
    h.requests[3].resolve(response([5], 'cleared')); await settle()
    assert.equal(h.api.fflogsUnavailable.value, false)
    h.close()
})

test('recomputes when the run target or current roster changes', async () => {
    const h = harness({ enabled: true })
    h.requests[0].resolve(response([1])); await settle()
    h.context.targetProgPointKey = 'last'; await settle()
    assert.deepEqual(h.api.records.value, {})
    h.requests[1].resolve(response([1], 'below_target')); await settle()
    h.context.slots = [{ assigned_character_id: 2 }]; await settle()
    assert.deepEqual(h.requests[2].options.params.character_ids, [2])
    assert.deepEqual(h.api.records.value, {})
    h.close()
    assert.equal(h.requests[2].options.signal.aborted, true)
})
