import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import ts from 'typescript'
import * as vue from 'vue'

function harness(overrides = {}) {
    const requests = []
    const cleanups = []
    const source = readFileSync(new URL('../../resources/js/composables/useApplicantRecord.ts', import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = {
        vue: { ...vue, onBeforeUnmount: callback => cleanups.push(callback) },
        'ziggy-js': { route: (_, params) => `${params.group}/${params.activity}/${params.application}` },
        axios: { default: { isCancel: error => error?.cancelled, get: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })) } },
    }
    const exports = {}
    new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name] }, exports)
    const props = vue.reactive({ open: true, shouldFetch: false, groupSlug: 'group', activityId: 1, applicationId: 2, ...overrides })
    const scope = vue.effectScope()
    const api = scope.run(() => exports.useApplicantRecord(props))
    return { props, api, requests, close: () => { cleanups.forEach(callback => callback()); scope.stop() } }
}
const record = count => ({ milestones: [{ key: 'boss', label: { en: 'Boss' }, onsite: { kills: count, progress_percent: 100 }, fflogs: null }], onsite_available: true, fflogs_status: 'error' })
const settle = async () => { await Promise.resolve(); await vue.nextTick() }

test('records load when the tab opens and keep onsite data during FFLogs failure', async () => {
    const h = harness()
    assert.equal(h.requests.length, 0)
    h.props.shouldFetch = true; await vue.nextTick()
    assert.equal(h.requests.length, 1)
    h.requests[0].resolve({ data: record(3) }); await settle()
    assert.equal(h.api.record.value.milestones[0].onsite.kills, 3)
    assert.equal(h.api.failed.value, false)
    h.props.shouldFetch = false; await vue.nextTick()
    h.props.shouldFetch = true; await vue.nextTick()
    assert.equal(h.requests.length, 1)
    h.close()
})

test('switching applicants aborts old requests and ignores late responses', async () => {
    const h = harness({ shouldFetch: true })
    h.props.applicationId = 3; await vue.nextTick()
    assert.equal(h.requests[0].options.signal.aborted, true)
    assert.equal(h.requests[1].url, 'group/1/3')
    h.requests[1].resolve({ data: record(5) }); await settle()
    h.requests[0].resolve({ data: record(99) }); await settle()
    assert.equal(h.api.record.value.milestones[0].onsite.kills, 5)
    assert.equal(h.api.loading.value, false)
    h.close()
})

test('failed requests can be retried and closing cancels pending work', async () => {
    const h = harness({ shouldFetch: true })
    h.requests[0].reject(new Error('Unavailable')); await settle()
    assert.equal(h.api.failed.value, true)
    const retry = h.api.reload()
    assert.equal(h.requests.length, 2)
    h.props.open = false; await vue.nextTick()
    assert.equal(h.requests[1].options.signal.aborted, true)
    h.requests[1].resolve({ data: record(8) }); await retry
    assert.equal(h.api.record.value, null)
    h.close()
})
