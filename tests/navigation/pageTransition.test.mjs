import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'

const flush = () => new Promise(resolve => setImmediate(resolve))

function harness() {
    const listeners = new Map()
    const windowListeners = new Map()
    const documentListeners = new Map()
    const frames = new Map()
    const timers = new Map()
    const calls = []
    let now = 0, id = 0, unmount, onMotion
    const motion = { matches: false, addEventListener: (_, fn) => { onMotion = fn }, removeEventListener: () => { onMotion = undefined } }
    const assigned = []
    const stored = new Map()
    class Element {
        constructor(link) { this.link = link }
        closest() { return this.link }
    }
    const window = {
        location: { origin: 'https://fullparty.test', pathname: '/en/home',
            get href() { return this.origin + this.pathname }, assign: href => assigned.push(href) },
        matchMedia: () => motion,
        addEventListener: (name, fn) => windowListeners.set(name, fn),
        removeEventListener: name => windowListeners.delete(name),
    }
    const document = {
        visibilityState: 'visible',
        documentElement: { dataset: {}, classList: { remove() {} } },
        addEventListener: (name, fn) => documentListeners.set(name, fn),
        removeEventListener: name => documentListeners.delete(name),
    }
    const modules = {
        vue: { nextTick: async () => {}, onMounted: fn => fn(), onBeforeUnmount: fn => { unmount = fn } },
        '@inertiajs/vue3': { router: { on: (name, fn) => { listeners.set(name, fn); return () => listeners.delete(name) } } },
        '@/utils/pageRippleCanvas': { createPageRippleRenderer: () => ({
            start: () => { calls.push('start'); return { x: 0, y: 0.5, seed: 1 } },
            draw: (progress, reveal) => calls.push({ progress, reveal }),
            clear: () => calls.push('clear'),
            color: () => '#27043a',
        }) },
    }
    const globals = {
        window, document, Element, performance: { now: () => now },
        sessionStorage: { setItem: (key, value) => stored.set(key, value) },
        requestAnimationFrame: fn => { frames.set(++id, fn); return id },
        cancelAnimationFrame: id => frames.delete(id),
        setTimeout: fn => { timers.set(++id, fn); return id },
        clearTimeout: id => timers.delete(id),
    }
    const source = readFileSync(new URL('../../resources/js/composables/usePageTransition.ts', import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', ...Object.keys(globals), code)(name => modules[name], exports, ...Object.values(globals))
    exports.usePageTransition({ value: {} })
    return {
        calls, window, document, frames, timers, assigned, stored,
        wait: exports.waitForPageTransition,
        visit(path = '/en/account/characters', extra = {}) {
            return { method: 'get', async: false, prefetch: false, preserveUrl: false, only: [], except: [], reset: [],
                url: new URL(path, window.location.origin), ...extra }
        },
        event: (name, detail = {}) => listeners.get(name)?.({ detail, defaultPrevented: false }),
        before: (visit, prevented = false) => listeners.get('before')({ detail: { visit }, defaultPrevented: prevented }),
        frame(milliseconds) {
            now += milliseconds
            const pending = [...frames.values()]
            frames.clear()
            pending.forEach(fn => fn(now))
        },
        reduce(value) { motion.matches = value; onMotion?.() },
        history(path) { window.location.pathname = path; windowListeners.get('popstate')() },
        timeout() { [...timers.values()].forEach(fn => fn()) },
        native(extra = {}, linkExtra = {}) {
            const link = { href: 'https://fullparty.test/en/account/characters', target: '',
                hasAttribute: () => false, getAttribute: () => null, ...linkExtra }
            const event = { defaultPrevented: false, button: 0, target: new Element(link),
                preventDefault() { this.defaultPrevented = true }, ...extra }
            const done = documentListeners.get('click')(event)
            return { event, done }
        },
        unmount() { unmount(); assert.equal(listeners.size + windowListeners.size + documentListeners.size, 0) },
    }
}

test('a fast destination waits for full cover, then reveals after navigation without waiting for lazy props', async () => {
    const h = harness()
    h.before(h.visit())
    await flush()
    let ready = false
    h.wait().then(() => { ready = true })
    h.frame(230)
    await flush()
    assert.equal(ready, false)
    h.frame(230)
    await flush()
    assert.equal(ready, true)
    assert.equal(h.calls.some(call => call.reveal), false)
    h.window.location.pathname = '/en/account/characters'
    h.event('navigate')
    await flush()
    h.frame(540)
    assert.equal(h.calls.at(-1), 'clear')
    assert.equal(h.frames.size, 0)
    h.unmount()
})

test('forms, background reloads, prefetches, query filters, anchors and cancelled guards do not animate', async () => {
    const h = harness()
    for (const visit of [
        h.visit('/en/home?page=2'), h.visit('/en/home#characters'),
        h.visit('/en/account/characters', { method: 'post' }),
        h.visit('/en/account/characters', { async: true }),
        h.visit('/en/account/characters', { prefetch: true }),
        h.visit('/en/account/characters', { only: ['characters'] }),
        h.visit('/en/account/characters', { except: ['groups'] }),
        h.visit('/en/account/characters', { reset: ['characters'] }),
        h.visit('https://another.example/'),
    ]) h.before(visit)
    h.before(h.visit(), true)
    await flush()
    assert.deepEqual(h.calls, [])
    await h.wait()
    h.unmount()
})

test('reduced motion skips the effect and turning it on releases an in-progress page swap immediately', async () => {
    const h = harness()
    h.reduce(true)
    h.before(h.visit())
    await flush()
    assert.equal(h.calls.includes('start'), false)
    h.reduce(false)
    h.before(h.visit())
    await flush()
    const pending = h.wait()
    h.reduce(true)
    await pending
    assert.equal(h.calls.at(-1), 'clear')
    assert.equal(h.frames.size, 0)
    h.unmount()
})

test('cancellation and the timeout release the page gate and cannot leave an overlay', async () => {
    const h = harness()
    const visit = h.visit()
    h.before(visit)
    await flush()
    const pending = h.wait()
    h.event('finish', { visit: { ...visit, cancelled: true } })
    await pending
    assert.equal(h.calls.at(-1), 'clear')
    h.before(h.visit())
    await flush()
    const next = h.wait()
    h.timeout()
    await next
    assert.equal(h.frames.size + h.timers.size, 0)
    h.unmount()
})

test('rapid navigation ignores completion of a superseded request', async () => {
    const h = harness()
    const first = h.visit()
    const second = h.visit('/en/groups')
    h.before(first)
    await flush()
    h.before(second)
    await flush()
    const previous = h.calls.length
    h.event('finish', { visit: { ...first, cancelled: true } })
    assert.equal(h.calls.length, previous)
    h.frame(460)
    h.window.location.pathname = '/en/groups'
    h.event('finish', { visit: second })
    await flush()
    h.frame(540)
    assert.equal(h.calls.at(-1), 'clear')
    h.unmount()
})

test('history navigation and cached replacement visits reveal after arriving', async () => {
    const h = harness()
    h.history('/en/groups')
    assert.equal(h.calls.at(-1), 'start')
    h.frame(460)
    h.event('navigate')
    await flush()
    h.frame(540)
    h.before(h.visit('/en/account/characters', { replace: true }))
    await flush()
    h.frame(460)
    h.window.location.pathname = '/en/account/characters'
    h.event('success')
    await flush()
    h.frame(540)
    assert.equal(h.calls.at(-1), 'clear')
    h.unmount()
})

test('native links cover first and keep document navigation with a one-use arrival handoff', async () => {
    const h = harness()
    const click = h.native()
    assert.equal(click.event.defaultPrevented, true)
    assert.deepEqual(h.assigned, [])
    h.frame(460)
    await click.done
    assert.deepEqual(h.assigned, ['https://fullparty.test/en/account/characters'])
    assert.equal(JSON.parse(h.stored.get('fullparty-page-ripple')).x, 0)
    h.timeout()
    assert.equal(h.calls.at(-1), 'clear')
    h.unmount()
})

test('native modified clicks, downloads, external links and OAuth links retain browser handling', async () => {
    const h = harness()
    for (const extra of [{ button: 1 }, { ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { defaultPrevented: true }]) {
        await h.native(extra).done
    }
    for (const extra of [
        { target: '_blank' }, { hasAttribute: name => name === 'download' },
        { href: 'https://another.example/run' }, { href: 'https://fullparty.test/en/auth/discord/redirect' },
        { href: 'https://fullparty.test/en/run/calendar.ics' },
    ]) {
        const click = h.native({}, extra)
        await click.done
        assert.equal(click.event.defaultPrevented, false)
    }
    assert.deepEqual(h.calls, [])
    h.unmount()
})

test('enabling reduced motion during a native cover releases navigation rather than losing the click', async () => {
    const h = harness()
    const click = h.native()
    h.reduce(true)
    await click.done
    assert.equal(h.assigned.length, 1)
    h.unmount()
})

test('the pre-paint arrival script consumes only a fresh matching handoff and has its own failsafe', () => {
    const blade = readFileSync(new URL('../../resources/views/app.blade.php', import.meta.url), 'utf8')
    const script = [...blade.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1])
        .find(source => source.includes('fullparty-page-ripple'))
    assert.ok(script)
    for (const scenario of ['valid', 'expired', 'wrong page', 'reduced', 'bad coordinates']) {
        const classes = new Set()
        const root = { dataset: {}, style: { setProperty() {} }, classList: {
            add: name => classes.add(name), remove: name => classes.delete(name),
        } }
        const data = { href: 'https://fullparty.test/en/groups', at: Date.now(), x: 0, y: 0.5, seed: 1, color: '#27043a' }
        if (scenario === 'expired') data.at -= 11000
        if (scenario === 'wrong page') data.href += '/different'
        if (scenario === 'bad coordinates') data.x = 2
        let removed = false, timeout
        new Function('sessionStorage', 'document', 'location', 'matchMedia', 'CSS', 'setTimeout', script)(
            { getItem: () => JSON.stringify(data), removeItem: () => { removed = true } },
            { documentElement: root }, { href: 'https://fullparty.test/en/groups' },
            () => ({ matches: scenario === 'reduced' }), { supports: () => true }, fn => { timeout = fn },
        )
        assert.equal(removed, true)
        assert.equal(classes.has('page-ripple-arriving'), scenario === 'valid')
        if (timeout) {
            timeout()
            assert.equal(classes.size, 0)
            assert.equal(root.dataset.pageRipple, undefined)
        }
    }
})
