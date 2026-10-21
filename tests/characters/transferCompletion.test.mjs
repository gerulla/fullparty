import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { computed, ref, watch, nextTick } from 'vue'

function setup({ preview = false, fail = false } = {}) {
    const code = ts.transpileModule(readFileSync(new URL('../../resources/js/composables/useCharacterTransferCompletion.ts', import.meta.url), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const calls = []
    const reloads = []
    let completed = 0
    const modules = {
        vue: { computed, ref, watch, onBeforeUnmount: () => {} },
        'vue-i18n': { useI18n: () => ({ t: key => key, te: () => true }) },
        'ziggy-js': { route: (name, id) => `${name}:${id}` },
        '@inertiajs/vue3': { router: { reload: options => reloads.push(options) } },
        axios: { default: {
            isAxiosError: () => true,
            post: async (url, data) => {
                calls.push({ url, data })
                if (fail) throw { response: { data: { errors: { transfer: ['duplicate'] } } } }
                return { data: { completed: true } }
            },
        } },
    }
    const exports = {}
    new Function('require', 'exports', code)(id => { assert.ok(id in modules, id); return modules[id] }, exports)
    const summary = ref({ id: 3, pending: true, runs: [] }), previewMode = ref(preview)
    const api = exports.useCharacterTransferCompletion(summary, previewMode, () => completed++)
    return { api, calls, reloads, summary, previewMode, completed: () => completed }
}

test('both decisions are required, even with empty summaries', async () => {
    const { api, calls, reloads, completed } = setup()
    await api.complete()
    api.history.value = 'keep'
    await api.complete()
    assert.equal(calls.length, 0)
    api.upcoming.value = 'discard'
    await api.complete()
    assert.deepEqual(calls, [{ url: 'characters.transfer.complete:3', data: { history: 'keep', upcoming: 'discard' } }])
    assert.equal(completed(), 1)
    assert.deepEqual(reloads, [{ only: ['account_flags'] }])
})

test('failed saves retain choices and never advance to success', async () => {
    const { api, reloads, completed } = setup({ fail: true })
    api.history.value = 'discard'
    api.upcoming.value = 'keep'
    await api.complete()
    assert.equal(completed(), 0)
    assert.equal(api.error.value, 'characters.transfer.errors.duplicate')
    assert.equal(api.history.value, 'discard')
    assert.equal(api.busy.value, false)
    assert.equal(reloads.length, 0)
})

test('preview confirmation never writes to the backend', async () => {
    const { api, calls, reloads, completed } = setup({ preview: true })
    api.history.value = 'keep'
    api.upcoming.value = 'keep'
    await api.complete()
    assert.equal(completed(), 1)
    assert.equal(calls.length, 0)
    assert.equal(reloads.length, 0)
})

test('preview choices cannot silently carry over into a live transfer', async () => {
    const { api, summary, previewMode } = setup({ preview: true })
    api.history.value = 'keep'
    api.upcoming.value = 'keep'
    previewMode.value = false
    summary.value = { id: 9, pending: true, runs: [] }
    await nextTick()
    assert.equal(api.ready.value, false)
    assert.equal(api.history.value, null)
    assert.equal(api.upcoming.value, null)
})
