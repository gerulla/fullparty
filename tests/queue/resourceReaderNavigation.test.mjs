import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'
import { resourceOutline } from '../../resources/js/utils/resourceOutline.ts'

function load(name, modules, globals = {}) {
    const source = readFileSync(new URL(`../../resources/js/composables/${name}.ts`, import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', ...Object.keys(globals), code)(id => { assert.ok(id in modules, id); return modules[id] }, exports, ...Object.values(globals))
    return exports
}
const heading = (text, level = 2) => ({ type: 'heading', attrs: { level }, content: [{ type: 'text', text }] })

test('contents preserve heading order, nesting and formatting without changing the saved document', () => {
    const original = { type: 'doc', content: [heading('Preparation'), heading('Assignments', 3), { type: 'paragraph', content: [{ type: 'text', text: 'Body' }] }, heading('準備 & 攻略'), heading('History')] }
    const before = structuredClone(original)
    const result = resourceOutline(original)
    assert.deepEqual(original, before)
    assert.deepEqual(result.sections.map(item => [item.title, item.depth]), [['Preparation', 0], ['Assignments', 1], ['準備 & 攻略', 0], ['History', 0]])
    assert.equal(result.sections[2].id, 'resource-section-準備-攻略')
    assert.notEqual(result.sections[3].id, 'resource-history')
    assert.equal(result.document.content[1].attrs.readerAnchor, result.sections[1].id)
    assert.deepEqual(resourceOutline(original), result)
})

test('repeated, empty and punctuation-only headings get safe distinct anchors', () => {
    const result = resourceOutline({ type: 'doc', content: [heading('Plan'), heading('Plan'), heading('Plan-2'), heading(''), heading('!'), { type: 'heading', attrs: { level: 3 }, content: [{ type: 'text', text: 'North', marks: [{ type: 'bold' }] }, { type: 'hardBreak' }, { type: 'text', text: 'party' }] }] })
    assert.equal(result.sections.length, 5)
    assert.equal(new Set(result.sections.map(item => item.id)).size, 5)
    assert.equal(result.sections.at(-1).title, 'North party')
    assert.equal(resourceOutline({ type: 'doc', content: [] }).sections.length, 0)
})

function appearance({ saved, appearance = 'system', unavailable = false } = {}) {
    let mount, unmount
    const scope = vue.effectScope()
    const library = vue.reactive({ customization: { appearance } })
    const storage = new Map(saved ? [['resource-reader-appearance', saved]] : [])
    const elements = Array.from({ length: 2 }, () => {
        const classes = new Set(['dark'])
        return { classList: { contains: name => classes.has(name), toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name) }, style: { colorScheme: 'dark', backgroundColor: '', color: '' } }
    })
    const { usePublicResourceAppearance } = load('usePublicResourceAppearance', {
        vue: { ...vue, onMounted: callback => { mount = callback }, onBeforeUnmount: callback => { unmount = callback } },
    }, {
        document: { documentElement: elements[0], body: elements[1] },
        localStorage: { getItem: key => { if (unavailable) throw Error(); return storage.get(key) }, setItem: (key, value) => { if (unavailable) throw Error(); storage.set(key, value) } },
    })
    const api = scope.run(() => usePublicResourceAppearance(() => library))
    scope.run(mount)
    return { api, elements, storage, library, stop() { unmount(); scope.stop() } }
}

test('visitor appearance overrides the library default, persists, and restores page styles on exit', async () => {
    const h = appearance({ appearance: 'dark' })
    assert.equal(h.api.isDark.value, true)
    h.api.toggle()
    await vue.nextTick()
    assert.equal(h.api.isDark.value, false)
    assert.equal(h.storage.get('resource-reader-appearance'), 'light')
    assert.ok(h.elements.every(item => item.classList.contains('light') && !item.classList.contains('dark')))
    const reloaded = appearance({ appearance: 'dark', saved: h.storage.get('resource-reader-appearance') })
    assert.equal(reloaded.api.isDark.value, false)
    reloaded.stop(); h.stop()
    assert.ok(h.elements.every(item => item.classList.contains('dark') && !item.classList.contains('light') && item.style.backgroundColor === ''))
})

test('unsaved preferences default to dark unless the group selects light, even without storage', async () => {
    const h = appearance({ unavailable: true })
    assert.equal(h.api.isDark.value, true)
    h.library.customization.appearance = 'light'
    await vue.nextTick()
    assert.equal(h.api.isDark.value, false)
    h.library.customization.appearance = 'system'
    await vue.nextTick()
    assert.equal(h.api.isDark.value, true)
    delete h.library.customization.appearance
    await vue.nextTick()
    assert.equal(h.api.isDark.value, true)
    assert.doesNotThrow(() => h.api.toggle())
    await vue.nextTick()
    assert.equal(h.api.isDark.value, false)
    h.stop()
})

function contents(hash = '') {
    let mount, unmount, callback
    const scope = vue.effectScope()
    const listeners = new Map()
    const observers = []
    const targets = new Map()
    const window = { location: { hash }, scrollY: 0, innerHeight: 844, addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener: name => listeners.delete(name) }
    const document = { documentElement: { scrollHeight: 3000 }, getElementById: id => targets.get(id) }
    const root = { contains: element => [...targets.values()].includes(element) }
    const sections = vue.ref(['heading', 'resource-discord-commands', 'resource-history'].map(id => ({ id, title: id, depth: 0 })))
    class Observer {
        constructor(fn) { this.fn = fn; observers.push(this) }
        observe() { this.connected = true }
        disconnect() { this.connected = false }
    }
    const { useResourceContents } = load('useResourceContents', { vue: { ...vue, onMounted: fn => { mount = fn }, onBeforeUnmount: fn => { unmount = fn } } }, {
        window, document, MutationObserver: Observer, ResizeObserver: Observer,
        requestAnimationFrame: fn => { callback = fn; return 1 }, cancelAnimationFrame: () => { callback = undefined },
    })
    const api = scope.run(() => useResourceContents(() => root, () => sections.value))
    scope.run(mount)
    function add(id, y) {
        const target = { y, jumps: 0, focused: false, getBoundingClientRect: () => ({ top: target.y - window.scrollY }), scrollIntoView: () => { target.jumps++; window.scrollY = Math.min(target.y - 32, 2156) }, focus: () => { target.focused = true } }
        targets.set(id, target)
        return target
    }
    return { api, window, listeners, observers, targets, add, sections, flush() { const fn = callback; callback = undefined; fn?.() }, stop() { unmount(); scope.stop() } }
}

test('deep links wait for rich-text headings and keep a clicked final section active at the bottom', () => {
    const h = contents('#resource-discord-commands')
    const command = h.add('resource-discord-commands', 300)
    h.add('resource-history', 600)
    h.flush()
    assert.equal(command.jumps, 0, 'do not jump while article headings are still mounting')
    h.add('heading', 500)
    command.y = 2300
    h.targets.get('resource-history').y = 2700
    h.observers[0].fn(); h.flush()
    assert.equal(command.jumps, 1)
    assert.equal(command.focused, true)
    assert.equal(h.api.activeId.value, 'resource-discord-commands')
    h.listeners.get('scroll')(); h.flush()
    assert.equal(h.api.activeId.value, 'resource-discord-commands')
    h.window.scrollY -= 100
    h.listeners.get('scroll')(); h.flush()
    assert.equal(h.api.activeId.value, 'heading')
    h.window.scrollY = 2156
    h.listeners.get('scroll')(); h.flush()
    assert.equal(h.api.activeId.value, 'resource-history')
    h.stop()
    assert.equal(h.listeners.size, 0)
    assert.ok(h.observers.every(item => !item.connected))
})

test('contents recover safely from malformed hashes and reset when navigating to another resource', async () => {
    const h = contents('#%invalid')
    h.add('heading', 100); h.add('resource-discord-commands', 1000); h.add('resource-history', 1500)
    h.flush()
    assert.equal(h.api.activeId.value, 'heading')
    h.window.location.hash = '#resource-history'
    h.listeners.get('hashchange')(); h.flush()
    assert.equal(h.api.activeId.value, 'resource-history')
    h.window.location.hash = ''
    h.sections.value = []
    await vue.nextTick(); h.flush()
    assert.equal(h.api.activeId.value, '')
    h.stop()
})
