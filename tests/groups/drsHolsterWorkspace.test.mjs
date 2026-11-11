import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'

function harness() {
    const source = readFileSync(new URL('../../resources/js/composables/useDrsHolsterWorkspace.ts', import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const events = new Map(), visits = [], prompts = [], cleanups = []
    let before, answer = false
    const window = {
        location: { href: '/content?type=drs' },
        history: { state: { page: 'editor' }, pushState(state, _, url) { this.state = state; window.location.href = url } },
        confirm: () => answer,
        addEventListener: (name, callback) => events.set(name, callback),
        removeEventListener: name => events.delete(name),
    }
    const modules = {
        vue: { ...vue, onBeforeUnmount: callback => cleanups.push(callback) },
        '@inertiajs/vue3': { router: { on: (_, callback) => { before = callback; return () => { before = null } }, visit: (...args) => visits.push(args) } },
        axios: { default: {} }, 'ziggy-js': { route: name => name },
        'vue-i18n': { useI18n: () => ({ t: key => key }) },
        '@nuxt/ui/composables': { useToast: () => ({ add() {} }) },
        '@/composables/useConfirmationModal': { useConfirmationModal: () => ({ open: async options => { prompts.push(options); return answer } }) },
        '@/utils/richText': { emptyRichTextDocument: () => ({ type: 'doc', content: [] }) },
    }
    const exports = {}
    new Function('require', 'exports', 'window', code)(name => { assert.ok(name in modules, name); return modules[name] }, exports, window)
    const scope = vue.effectScope()
    const api = scope.run(() => exports.useDrsHolsterWorkspace('group', { holsters: [{ id: 1, name: { en: 'Tank' }, type: 'prepop', role: 'tank', notes: 'Saved notes', items: [] }], bozja_items: [] }))
    return {
        api, window, events, visits, prompts,
        answer: value => { answer = value },
        navigate: event => before(event),
        stop: () => { cleanups.forEach(callback => callback()); scope.stop() },
    }
}

test('returning to the library keeps cancelled edits and restores the saved draft only after confirmation', async () => {
    const h = harness()
    assert.equal(await h.api.leaveEditor(), true)
    assert.equal(h.prompts.length, 0)
    h.api.draft.notes = 'Unsaved notes'
    assert.equal(await h.api.leaveEditor(), false)
    assert.equal(h.api.draft.notes, 'Unsaved notes')
    assert.equal(h.api.dirty, true)
    h.answer(true)
    assert.equal(await h.api.leaveEditor(), true)
    assert.equal(h.api.draft.notes, 'Saved notes')
    assert.equal(h.api.dirty, false)
    h.stop()
})

test('page navigation waits for discard confirmation and preserves the requested destination', async () => {
    const h = harness()
    h.api.draft.name.en = 'Unsaved name'
    const visit = { url: new URL('https://fullparty.test/en/groups'), method: 'get', preserveScroll: true }
    let prevented = 0
    const event = { detail: { visit }, preventDefault: () => prevented++ }
    h.navigate(event)
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(prevented, 1)
    assert.equal(h.visits.length, 0)
    assert.equal(h.api.draft.name.en, 'Unsaved name')
    h.answer(true)
    h.navigate(event)
    await new Promise(resolve => setImmediate(resolve))
    assert.deepEqual(h.visits, [[visit.url, visit]])
    assert.equal(h.api.dirty, false)
    h.navigate(event)
    assert.equal(prevented, 2)
    h.stop()
})

test('cancelled browser Back retains the editor state and draft; clean history navigation needs no warning', () => {
    const h = harness()
    h.api.draft.notes = 'Keep this draft'
    h.window.location.href = '/content'
    h.window.history.state = { page: 'library' }
    let stopped = 0
    h.events.get('popstate')({ stopImmediatePropagation: () => stopped++ })
    assert.equal(stopped, 1)
    assert.equal(h.window.location.href, '/content?type=drs')
    assert.deepEqual(h.window.history.state, { page: 'editor' })
    assert.equal(h.api.draft.notes, 'Keep this draft')
    h.answer(true)
    h.events.get('popstate')({ stopImmediatePropagation: () => stopped++ })
    assert.equal(stopped, 1)
    let warned = false
    h.events.get('beforeunload')({ preventDefault: () => { warned = true } })
    assert.equal(warned, true)
    h.api.draft.notes = 'Saved notes'
    h.answer(false)
    h.events.get('popstate')({ stopImmediatePropagation: () => stopped++ })
    assert.equal(stopped, 1)
    h.stop()
    assert.equal(h.events.size, 0)
})
