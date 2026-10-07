import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import test from 'node:test'
import { fileURLToPath } from 'node:url'
import { JSDOM } from 'jsdom'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'

const require = createRequire(import.meta.url)
const root = new URL('../../', import.meta.url)

test('the real resource embed preview escapes code-fence injection while preserving Markdown', async () => {
    const dom = new JSDOM('<!doctype html><html><body><div id="preview"></div></body></html>', {
        url: 'https://fullparty.test', pretendToBeVisual: true, runScripts: 'outside-only',
    })
    const names = ['window', 'document', 'navigator', 'Element', 'HTMLElement', 'SVGElement', 'Node', 'MutationObserver', 'getComputedStyle', 'ResizeObserver', 'requestAnimationFrame', 'cancelAnimationFrame', 'fetch']
    const originals = new Map(names.map(name => [name, Object.getOwnPropertyDescriptor(globalThis, name)]))
    let app
    try {
        for (const name of names) {
            const value = ['getComputedStyle', 'requestAnimationFrame', 'cancelAnimationFrame'].includes(name)
                ? dom.window[name].bind(dom.window) : dom.window[name]
            Object.defineProperty(globalThis, name, { configurable: true, writable: true, value })
        }
        globalThis.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} }
        globalThis.fetch = dom.window.fetch = () => { throw new Error('Security regression must not access the network') }
        dom.window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} })

        const vue = require('vue')
        const modules = { vue, 'md-editor-v3': require('md-editor-v3') }
        const evaluate = (source, filename) => {
            const compiled = ts.transpileModule(source, {
                fileName: filename,
                compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
            }).outputText
            const exports = {}
            new Function('require', 'exports', compiled)(name => {
                if (name.endsWith('.css')) return {}
                assert.ok(Object.hasOwn(modules, name), `Unexpected preview dependency: ${name}`)
                return modules[name]
            }, exports)
            return exports
        }
        // Compile the actual component and execute the app's sanitizer configuration.
        // Only CSS is omitted; this must catch regressions in both renderer and setup.
        const bootstrap = new URL('resources/js/bootstrap/markdownEditor.js', root)
        modules['@/bootstrap/markdownEditor.js'] = evaluate(readFileSync(bootstrap, 'utf8'), fileURLToPath(bootstrap))
        const filename = fileURLToPath(new URL('resources/js/components/Groups/Resources/ResourceEmbedMarkdown.vue', root))
        const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename })
        const component = evaluate(compileScript(descriptor, { id: 'security-preview', inlineTemplate: true }).content, filename).default
        const markdown = [
            '```x"><details/open/ontoggle=window.__fullpartyXssMarker=1>',
            'SAFE',
            '```',
            '',
            '**Raid instructions**',
            '',
            '[Guide](https://fullparty.gg/guide)',
        ].join('\n')
        app = vue.createApp({ render: () => vue.h(component, { content: markdown }) })
        app.mount('#preview')
        await vue.nextTick()
        await new Promise(resolve => setTimeout(resolve, 100))

        const preview = dom.window.document.querySelector('#preview')
        assert.equal(preview.querySelectorAll('script, iframe, details[ontoggle]').length, 0)
        for (const element of preview.querySelectorAll('*')) {
            assert.equal([...element.attributes].some(attribute => /^on/i.test(attribute.name)), false, element.outerHTML)
        }
        assert.match(preview.querySelector('pre').textContent, /SAFE/)
        assert.equal(preview.querySelector('strong').textContent, 'Raid instructions')
        assert.equal(preview.querySelector('a[href="https://fullparty.gg/guide"]').textContent, 'Guide')
        assert.equal(dom.window.__fullpartyXssMarker, undefined)
    } finally {
        app?.unmount()
        dom.window.close()
        for (const [name, descriptor] of originals) {
            if (descriptor) Object.defineProperty(globalThis, name, descriptor)
            else delete globalThis[name]
        }
    }
})
