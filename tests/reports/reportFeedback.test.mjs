import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { effectScope, computed, ref, watch, nextTick } from 'vue'
import { parse, compileScript } from '@vue/compiler-sfc'

function feedbackHarness({ user = 1, get, post }) {
    const page = { props: { auth: ref(null) } }
    const auth = ref(user ? { user: { id: user } } : null)
    Object.defineProperty(page.props, 'auth', { get: () => auth.value })
    let mounted, unmounted, navigation
    const listeners = new Map()
    const fakeDocument = { visibilityState: 'visible', addEventListener: (key, fn) => listeners.set(key, fn), removeEventListener: key => listeners.delete(key) }
    const source = readFileSync(new URL('../../resources/js/composables/useReportFeedback.ts', import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = {
        vue: { computed, ref, watch, onMounted: fn => { mounted = fn }, onUnmounted: fn => { unmounted = fn } },
        '@inertiajs/vue3': { usePage: () => page, router: { on: (_event, fn) => { navigation = fn; return () => { navigation = undefined } } } },
        axios: { default: { get, post } }, 'ziggy-js': { route: (name, id) => name + (id ? '/' + id : '') },
    }
    const exports = {}
    new Function('require', 'exports', 'document', code)(name => modules[name], exports, fakeDocument)
    const scope = effectScope()
    const api = scope.run(() => exports.useReportFeedback())
    mounted()
    return { api, auth, navigate: () => navigation?.(), stop: () => { unmounted(); scope.stop() }, listeners }
}
const tick = async () => { await nextTick(); await Promise.resolve(); await Promise.resolve() }
const feedback = id => ({ id, audience: 'reporter', template: 'no_violation', item_title: 'Guide', message: null })

test('does not fetch for guests or acknowledge by opening, refreshing or navigating', async () => {
    let gets = 0, posts = 0
    const h = feedbackHarness({ user: null, get: async () => { gets++; return { data: { next: feedback(1), count: 1 } } }, post: async () => { posts++ } })
    await tick()
    assert.equal(gets, 0)
    h.auth.value = { user: { id: 4 } }
    await tick()
    assert.equal(gets, 1)
    assert.equal(h.api.next.value.id, 1)
    h.navigate(); await tick()
    assert.equal(posts, 0)
    assert.equal(gets, 1)
    h.stop()
    assert.equal(h.listeners.size, 0)
})

test('a failed acknowledgement keeps the message open and close can retry', async () => {
    let attempts = 0
    const h = feedbackHarness({ get: async () => ({ data: { next: feedback(1), count: 2 } }), post: async () => {
        if (++attempts === 1) throw Error('Offline')
        return { data: { next: feedback(2), count: 1 } }
    } })
    await tick()
    await h.api.acknowledge()
    assert.equal(h.api.failed.value, true)
    assert.equal(h.api.next.value.id, 1)
    await h.api.acknowledge()
    assert.equal(h.api.failed.value, false)
    assert.equal(h.api.next.value.id, 2)
    assert.equal(h.api.count.value, 1)
    h.stop()
})

test('owner notices and report feedback use one queue and only advance on explicit acknowledgement', async () => {
    const ownerNotice = { ...feedback(1), audience: 'owner', template: 'hidden' }
    let posts = 0
    const h = feedbackHarness({ get: async () => ({ data: { next: ownerNotice, count: 2 } }), post: async () => {
        posts++
        return { data: { next: feedback(2), count: 1 } }
    } })
    await tick()
    assert.equal(h.api.next.value.audience, 'owner')
    h.navigate(); await tick()
    assert.equal(posts, 0)
    await h.api.acknowledge()
    assert.equal(posts, 1)
    assert.equal(h.api.next.value.audience, 'reporter')
    assert.equal(h.api.count.value, 1)
    h.stop()
})

test('deduplicates double close clicks and never displays the previous accounts feedback after logout', async () => {
    let finish, posts = 0
    const h = feedbackHarness({ get: async () => ({ data: { next: feedback(1), count: 1 } }), post: () => { posts++; return new Promise(resolve => { finish = resolve }) } })
    await tick()
    const closing = h.api.acknowledge()
    await h.api.acknowledge()
    assert.equal(posts, 1)
    h.auth.value = null
    await tick()
    finish({ data: { next: feedback(2), count: 1 } })
    await closing
    assert.equal(h.api.next.value, null)
    h.stop()
})

test('discards stale pending data and loads the new account after an in-flight request finishes', async () => {
    let finish, gets = 0
    const h = feedbackHarness({ get: () => {
        if (++gets === 1) return new Promise(resolve => { finish = resolve })
        return Promise.resolve({ data: { next: feedback(9), count: 1 } })
    }, post: async () => {} })
    h.auth.value = { user: { id: 99 } }
    await tick()
    finish({ data: { next: feedback(1), count: 1 } })
    await tick(); await tick()
    assert.equal(gets, 2)
    assert.equal(h.api.next.value.id, 9)
    h.stop()
})

test('the reusable report modal compiles independently before any page uses it', () => {
    const filename = 'ReportModal.vue'
    const source = readFileSync(new URL('../../resources/js/components/Shared/Reports/ReportModal.vue', import.meta.url), 'utf8')
    const { descriptor } = parse(source, { filename })
    const compiled = compileScript(descriptor, { id: 'report-test', inlineTemplate: true })
    const result = ts.transpileModule(compiled.content, { reportDiagnostics: true, compilerOptions: { target: ts.ScriptTarget.ES2022 } })
    assert.equal(result.diagnostics?.length ?? 0, 0)
})
