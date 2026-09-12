import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { ref } from 'vue'
import { validateResourceFields, resourceFieldPath } from '../../resources/js/utils/resourceValidation.ts'

function load(name, modules, globals = {}) {
    const source = readFileSync(new URL(`../../resources/js/composables/${name}.ts`, import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', ...Object.keys(globals), code)(id => { assert.ok(id in modules, id); return modules[id] }, exports, ...Object.values(globals))
    return exports
}

test('autosave debounces typing, preserves in-flight edits, and saves the newer document next', async () => {
    let changed, timer, resolve, value = 'first', baseline = ''
    const sent = []
    const { useResourceAutosave } = load('useResourceAutosave', { vue: { ref, watch: (_, callback) => { changed = callback }, onBeforeUnmount: () => {} } }, {
        setTimeout: callback => { timer = callback; return 1 }, clearTimeout: () => {},
    })
    const api = useResourceAutosave({ enabled: () => true, changed: () => value !== baseline, value: () => value, backup: () => {},
        save: async () => { const current = value; sent.push(current); await new Promise(yes => { resolve = yes }); baseline = current; return true },
    })
    changed(); value = 'second'; changed()
    assert.deepEqual(sent, [])
    const saving = api.flush()
    assert.equal(api.saving.value, true)
    value = 'third'; changed()
    resolve(); await saving
    assert.deepEqual(sent, ['second'])
    assert.equal(baseline, 'second')
    timer()
    assert.deepEqual(sent, ['second', 'third'])
    resolve(); await new Promise(yes => setImmediate(yes))
    assert.equal(baseline, 'third')
    assert.equal(api.saving.value, false)
})

test('failed autosaves keep the recovery copy and can be retried deliberately', async () => {
    let attempts = 0, backups = 0, dirty = true
    const { useResourceAutosave } = load('useResourceAutosave', { vue: { ref, watch: () => {}, onBeforeUnmount: () => {} } })
    const api = useResourceAutosave({ enabled: () => true, changed: () => dirty, value: () => 'text', backup: () => backups++, save: async () => { attempts++; if (attempts === 1) return false; dirty = false; return true } })
    assert.equal(await api.flush(), false)
    assert.equal(dirty, true)
    assert.equal(backups, 1)
    assert.equal(await api.flush(), true)
    assert.equal(attempts, 2)
})

test('Inertia navigation waits for autosave, cancels on failure, and ignores quota-only reloads', async () => {
    let before, success = false, unmount, stopped = false
    const visits = []
    const router = { on: (event, callback) => { assert.equal(event, 'before'); before = callback; return () => { stopped = true } }, visit: (...args) => visits.push(args) }
    load('useResourceNavigation', { '@inertiajs/vue3': { router }, vue: { onMounted: callback => callback(), onBeforeUnmount: callback => { unmount = callback } } })
        .useResourceNavigation(() => true, async () => success)
    const visit = { url: new URL('https://fullparty.test/en/groups/example'), only: [] }
    let cancelled = 0
    before({ detail: { visit }, preventDefault: () => cancelled++ })
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(visits.length, 0)
    success = true
    before({ detail: { visit }, preventDefault: () => cancelled++ })
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(visits.length, 1)
    assert.equal(visits[0][0], visit.url)
    before({ detail: { visit: { ...visit, only: ['library'] } }, preventDefault: () => cancelled++ })
    assert.equal(cancelled, 2)
    unmount(); assert.equal(stopped, true)
})

test('an expired editing lease is renewed with the known version and a new token before retrying', async () => {
    let version = 1, expired = false, heartbeat
    const calls = []
    const { useResourceMutations } = load('useResourceMutations', {
        vue: { ref }, 'ziggy-js': { route: (_, params) => params.operation }, '@/utils/resourceWorkspaceData': { workspaceResource: value => value },
        axios: { default: { isAxiosError: error => !!error.response, post: async (operation, data) => {
            calls.push([operation, data])
            assert.equal(data.version, version)
            if (operation === 'heartbeat' && expired) { expired = false; throw { response: { status: 409 } } }
            version++
            return { data: { data: { version, resource: {}, ...(operation === 'acquire' ? { editing_token: `token-${version}` } : {}) } } }
        } } },
    }, { setInterval: callback => { heartbeat = callback; return 1 }, clearInterval: () => {} })
    const api = useResourceMutations(() => 'group', () => new Map(), assert.fail)
    await api.acquire('42', 1)
    expired = true; heartbeat()
    await new Promise(resolve => setImmediate(resolve))
    await api.mutate('42', 1, 'autosave', { content: {} })
    assert.deepEqual(calls.map(call => call[0]), ['acquire', 'heartbeat', 'acquire', 'heartbeat', 'autosave'])
    assert.equal(calls.at(-1)[1].editing_token, 'token-3')
})

test('field validation identifies nested embed fields and all resource inputs without backend requests', () => {
    const draft = {
        title: 'x'.repeat(201), description: 'x'.repeat(1001), body: { type: 'doc', content: [] }, tags: Array(21).fill('tag'),
        activityTypeIds: Array(31).fill(1), embeds: [{ command: 'list', title: '', description: '', color: '#oops', url: 'not a URL', image: '', thumbnail: '', fields: [{ name: '', value: '' }] }],
    }
    const errors = validateResourceFields(draft, [], '1', key => key)
    for (const path of ['title', 'description', 'tags', 'activity_type_ids', 'commands.0.name', 'commands.0.embed.color', 'commands.0.embed.url', 'commands.0.embed.fields.0.name', 'commands.0.embed.fields.0.value']) assert.ok(errors[path], path)
    assert.equal(resourceFieldPath('content.commands.0.embed.image.asset_id'), 'commands.0.embed.image')
    assert.equal(resourceFieldPath('content.body.content.0'), 'body')
})
