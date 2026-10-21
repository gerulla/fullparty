import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue'

function load(file, modules = {}, globals = {}) {
    const source = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const exports = {}
    new Function('require', 'exports', ...Object.keys(globals), source)(id => {
        assert.ok(id in modules, id)
        return modules[id]
    }, exports, ...Object.values(globals))
    return exports
}

const presentation = load('../../resources/js/utils/accountFlags.ts')
const flag = (id, severity = 'warning') => ({ id, severity })

function setup() {
    const mounts = [], unmounts = [], requests = [], listeners = new Map()
    const page = reactive({ props: { auth: { user: { id: 1 } }, account_flags: [flag('existing')] } })
    let timer, cleared = false
    const document = {
        visibilityState: 'visible',
        addEventListener: (event, callback) => listeners.set(event, callback),
        removeEventListener: event => listeners.delete(event),
    }
    const { useAccountFlags } = load('../../resources/js/composables/useAccountFlags.ts', {
        vue: { computed, ref, watch, onMounted: fn => mounts.push(fn), onBeforeUnmount: fn => unmounts.push(fn) },
        '@inertiajs/vue3': { usePage: () => page },
        'ziggy-js': { route: name => name },
        '@/utils/accountFlags': presentation,
        axios: { default: {
            isAxiosError: error => !!error.response,
            get: (url, config) => new Promise((resolve, reject) => requests.push({ url, config, resolve, reject })),
        } },
    }, {
        document, window: document,
        setInterval: fn => { timer = fn; return 1 },
        clearInterval: () => { cleared = true },
    })
    const scope = effectScope()
    const api = scope.run(useAccountFlags)
    mounts.forEach(fn => fn())
    return {
        api, page, requests, listeners, document,
        tick: () => timer(),
        stop: () => { unmounts.forEach(fn => fn()); scope.stop() },
        timerCleared: () => cleared,
    }
}

test('error takes precedence over warning and info without mutating the shared flags', () => {
    const input = [flag('info', 'info'), flag('warning'), flag('error', 'error'), flag('warning-2')]
    assert.deepEqual(presentation.sortAccountFlags(input).map(f => f.id), ['error', 'warning', 'warning-2', 'info'])
    assert.equal(input[0].id, 'info')
    assert.deepEqual(presentation.sortAccountFlags([]), [])
})

test('connection failures retain unresolved flags and successful refreshes clear resolved flags', async () => {
    const state = setup()
    state.requests[0].reject(new Error('offline'))
    await nextTick()
    assert.equal(state.api.flags.value[0].id, 'existing')
    state.tick()
    state.requests[1].resolve({ data: { flags: [] } })
    await nextTick()
    assert.deepEqual(state.api.flags.value, [])
    state.stop()
})

test('an old polling response cannot restore flags after logout or replace newer navigation data', async () => {
    const state = setup()
    state.page.props.auth.user = null
    await nextTick()
    assert.equal(state.requests[0].config.signal.aborted, true)
    state.requests[0].resolve({ data: { flags: [flag('old-account')] } })
    await nextTick()
    assert.deepEqual(state.api.flags.value, [])

    state.page.props.auth.user = { id: 2 }
    state.page.props.account_flags = [flag('new-account')]
    await nextTick()
    state.tick()
    state.page.props.account_flags = [flag('newer-navigation')]
    await nextTick()
    state.requests[1].resolve({ data: { flags: [flag('stale-poll')] } })
    await nextTick()
    assert.equal(state.api.flags.value[0].id, 'newer-navigation')
    state.stop()
})

test('polling pauses when hidden and unmount aborts work and removes listeners', async () => {
    const state = setup()
    state.requests[0].resolve({ data: { flags: [] } })
    await nextTick()
    state.document.visibilityState = 'hidden'
    state.tick()
    assert.equal(state.requests.length, 1)
    state.document.visibilityState = 'visible'
    state.listeners.get('visibilitychange')()
    assert.equal(state.requests.length, 2)
    state.stop()
    assert.equal(state.requests[1].config.signal.aborted, true)
    assert.equal(state.timerCleared(), true)
    assert.equal(state.listeners.size, 0)
    state.requests[1].resolve({ data: { flags: [flag('after-unmount')] } })
    await nextTick()
    assert.deepEqual(state.api.flags.value, [])
})
