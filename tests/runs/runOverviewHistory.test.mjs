import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import ts from 'typescript'

const site = 'https://fullparty.test'
const run = '/en/groups/party/activities/51'
const results = '/en/runs?search=1&page=3&sort=most_full#results'
const source = readFileSync(new URL('../../resources/js/bootstrap/runOverviewHistory.ts', import.meta.url), 'utf8')
const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText

function open(path, { entries, referrer } = {}) {
    const { window } = new JSDOM('', { url: site + path, referrer })
    if (entries) {
        window.navigation = {
            currentEntry: { index: entries.length - 1 },
            entries: () => entries.map(href => ({ url: href && new URL(href, site).href })),
        }
    }
    const listeners = new Map()
    const router = {
        restore: key => window.history.state?.rememberedState?.[key],
        on(name, listener) {
            listeners.set(name, listener)
            return () => listeners.delete(name)
        },
    }
    const exports = {}
    new Function('require', 'exports', 'window', 'location', 'history', 'document', code)(
        () => ({ router }), exports, window, window.location, window.history, window.document,
    )
    exports.initializeRunOriginTracking()
    return {
        origin: () => exports.runOrigin(run),
        visit(url, { replace = false, component = 'Groups/Activities/Overview' } = {}) {
            const page = { component, url, rememberedState: {} }
            listeners.get('start')?.({ detail: { visit: { async: false, replace } } })
            listeners.get('beforeUpdate')?.({ detail: { page } })
            window.history[replace ? 'replaceState' : 'pushState'](page, '', url)
        },
    }
}

test('native history returns to the originating results entry across a same-run application visit', () => {
    const localizedRun = run.replace('/en/', '/de/')
    const entries = ['/en/home', results, run, localizedRun, localizedRun + '/application?view=status', localizedRun]
    assert.deepEqual(open(localizedRun, { entries }).origin(), { href: site + results, steps: 4 })
    assert.equal(open(run, { entries: [results, null, run] }).origin(), null)
})

test('Inertia history keeps the source through same-run visits and accounts for replaced entries', () => {
    const tab = open(results)
    tab.visit(run)
    assert.deepEqual(tab.origin(), { href: site + results, steps: 1 })
    tab.visit(run + '/application', { component: 'Groups/Activities/Application' })
    tab.visit(run + '/application-confirmation', { replace: true, component: 'Groups/Activities/ApplicationConfirmation' })
    tab.visit(run)
    assert.deepEqual(tab.origin(), { href: site + results, steps: 3 })

    const calendar = '/en/groups/party/dashboard/activities'
    for (const entries of [undefined, ['/en/home', calendar]]) {
        const replaced = open(calendar, { entries })
        replaced.visit(run, { replace: true })
        assert.deepEqual(replaced.origin(), { href: site + calendar, steps: 0 })
    }
})

test('a new tab uses a same-site source as a link and direct or external entries use the page fallback', () => {
    for (const entries of [undefined, [run]]) {
        assert.deepEqual(open(run, { referrer: site + results, entries }).origin(), { href: site + results, steps: 0 })
        assert.equal(open(run, { entries }).origin(), null)
        assert.equal(open(run, { referrer: 'https://example.org/news', entries }).origin(), null)
    }
})
