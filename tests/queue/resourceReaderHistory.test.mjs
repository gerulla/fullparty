import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { effectScope, ref, watch, onScopeDispose, nextTick } from 'vue'

const entry = id => ({ id, editor: { name: 'Editor' }, summary: `Edit ${id}`, created_at: '2026-09-11T12:00:00Z' })
const document = (id = 1) => ({ id, history: { data: [6, 5, 4].map(entry), has_more: true } })
function harness(get) {
    const source = readFileSync(new URL('../../resources/js/composables/useResourceReaderHistory.ts', import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = { vue: { ref, watch, onScopeDispose }, axios: { default: { get } } }
    const exports = {}
    new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name] }, exports)
    const resource = ref(document())
    const scope = effectScope()
    const api = scope.run(() => exports.useResourceReaderHistory(() => resource.value, () => `/resources/${resource.value.id}/history`))
    return { api, resource, stop: () => scope.stop() }
}

test('keeps the initial three edits and loads all remaining history once without duplicate entries', async () => {
    const calls = []
    let finish
    const h = harness((...args) => { calls.push(args); return new Promise(resolve => { finish = resolve }) })
    assert.equal(calls.length, 0)
    assert.deepEqual(h.api.entries.value.map(item => item.id), [6, 5, 4])
    const loading = h.api.loadMore()
    await h.api.loadMore()
    assert.equal(calls.length, 1)
    assert.equal(calls[0][0], '/resources/1/history')
    assert.deepEqual(calls[0][1].params, { before: 4 })
    finish({ data: { data: [4, 3, 2, 1].map(entry) } })
    await loading
    assert.deepEqual(h.api.entries.value.map(item => item.id), [6, 5, 4, 3, 2, 1])
    assert.equal(h.api.hasMore.value, false)
    assert.equal(h.api.loading.value, false)
    await h.api.loadMore()
    assert.equal(calls.length, 1)
    h.stop()
})

test('retains the preview and allows retry after a failed history request', async () => {
    let attempts = 0
    const h = harness(async () => { if (++attempts === 1) throw new Error('Network unavailable'); return { data: { data: [3, 2, 1].map(entry) } } })
    await h.api.loadMore()
    assert.equal(h.api.failed.value, true)
    assert.equal(h.api.hasMore.value, true)
    assert.deepEqual(h.api.entries.value.map(item => item.id), [6, 5, 4])
    await h.api.loadMore()
    assert.equal(h.api.failed.value, false)
    assert.equal(h.api.entries.value.length, 6)
    h.stop()
})

test('publication cursors stay distinct from edit IDs when appending mixed history', async () => {
    let before
    const h = harness(async (_url, options) => { before = options.params.before; return { data: { data: [entry('publication-2'), entry(2), entry('publication-1'), entry(1)] } } })
    h.resource.value = { id: 1, history: { data: [entry('publication-3'), entry(3), entry('publication-2')], has_more: true } }
    await nextTick(); await h.api.loadMore()
    assert.equal(before, 'publication-2')
    assert.deepEqual(h.api.entries.value.map(item => item.id), ['publication-3', 3, 'publication-2', 2, 'publication-1', 1])
    h.stop()
})

test('does not append an old resource response after navigation', async () => {
    let finish, signal
    const h = harness((url, options) => { signal = options.signal; return new Promise(resolve => { finish = resolve }) })
    const loading = h.api.loadMore()
    h.resource.value = { id: 2, history: { data: [entry(20)], has_more: false } }
    await nextTick()
    assert.equal(signal.aborted, true)
    finish({ data: { data: [3, 2, 1].map(entry) } })
    await loading
    assert.deepEqual(h.api.entries.value.map(item => item.id), [20])
    assert.equal(h.api.hasMore.value, false)
    assert.equal(h.api.failed.value, false)
    h.stop()
})

test('cancels an in-flight history request when the reader is closed', async () => {
    let finish, signal
    const h = harness((url, options) => { signal = options.signal; return new Promise(resolve => { finish = resolve }) })
    const loading = h.api.loadMore()
    h.stop()
    assert.equal(signal.aborted, true)
    finish({ data: { data: [entry(3)] } })
    await loading
    assert.deepEqual(h.api.entries.value.map(item => item.id), [6, 5, 4])
})
