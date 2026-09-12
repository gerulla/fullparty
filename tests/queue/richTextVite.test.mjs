import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import ts from 'typescript'
import { parse } from '@vue/compiler-sfc'
import { optimizeDeps, resolveConfig } from 'vite'
import config from '../../vite.config.js'

const root = fileURLToPath(new URL('../../', import.meta.url))

test('Vite prebundles the editor imports used inside excluded Nuxt UI components', () => {
    for (const name of ['Editor', 'EditorToolbar']) {
        const filename = path.join(root, `node_modules/@nuxt/ui/dist/runtime/components/${name}.vue`)
        const { descriptor } = parse(readFileSync(filename, 'utf8'))
        const source = ts.createSourceFile(filename, descriptor.scriptSetup.content, ts.ScriptTarget.Latest)
        for (const node of source.statements) {
            if (!ts.isImportDeclaration(node)) continue
            const dependency = node.moduleSpecifier.text
            if (dependency.startsWith('@tiptap/')) {
                assert.ok(config.optimizeDeps.include.includes(dependency), `${name} needs prebundled ${dependency}`)
            }
        }
    }
    assert.ok(config.resolve.dedupe.includes('prosemirror-state'))
})

test('the actual Vite prebundled editor and custom extensions share a plugin registry', async () => {
    const cacheDir = mkdtempSync(path.join(tmpdir(), 'fullparty-richtext-vite-'))
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://fullparty.test', pretendToBeVisual: true })
    const globals = ['window', 'document', 'navigator', 'Node', 'HTMLElement', 'Element', 'MutationObserver', 'DOMParser', 'getComputedStyle', 'requestAnimationFrame', 'cancelAnimationFrame']
    const originals = new Map(globals.map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]))
    try {
        for (const key of globals) {
            const value = ['getComputedStyle', 'requestAnimationFrame', 'cancelAnimationFrame'].includes(key) ? dom.window[key].bind(dom.window) : dom.window[key]
            Object.defineProperty(globalThis, key, { configurable: true, value })
        }
        // Exercise dev prebundling, not a production build or a browser session.
        const resolved = await resolveConfig({ configFile: false, envFile: false, root, cacheDir, logLevel: 'silent', resolve: config.resolve, optimizeDeps: { ...config.optimizeDeps, noDiscovery: true } }, 'serve')
        const metadata = await optimizeDeps(resolved, true)
        const modules = {}
        for (const dependency of config.optimizeDeps.include) {
            modules[dependency] = await import(pathToFileURL(metadata.optimized[dependency].file).href)
        }
        const source = readFileSync(path.join(root, 'resources/js/utils/richTextExtensions.ts'), 'utf8')
        const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
        const exports = {}
        new Function('require', 'exports', compiled)(name => {
            assert.ok(modules[name], `Extension needs prebundled ${name}`)
            return modules[name]
        }, exports)

        const { Editor } = modules['@tiptap/vue-3']
        const StarterKit = modules['@tiptap/starter-kit'].default
        const Code = modules['@tiptap/extension-code'].default
        const HorizontalRule = modules['@tiptap/extension-horizontal-rule'].default
        const Image = modules['@tiptap/extension-image'].default
        const editors = []
        try {
            for (let index = 0; index < 3; index++) {
                const editor = new Editor({
                    element: document.createElement('div'), editable: index !== 2,
                    extensions: [StarterKit.configure({ code: false, horizontalRule: false }), Code.extend({ excludes: 'code' }), HorizontalRule, Image, ...exports.richTextExtensions()],
                    content: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Bridge positions' }] }] },
                })
                editors.push(editor)
                const keys = editor.state.plugins.map(plugin => plugin.key)
                assert.equal(new Set(keys).size, keys.length)
                assert.equal(editor.getText(), 'Bridge positions')
            }
            editors[0].commands.selectAll()
            editors[0].commands.toggleBold()
            assert.match(editors[0].getHTML(), /<strong>Bridge positions<\/strong>/)
            editors[1].commands.insertTable({ rows: 2, cols: 2 })
            assert.match(editors[1].getHTML(), /<table/)
        } finally {
            for (const editor of editors) editor.destroy()
        }
    } finally {
        dom.window.close()
        for (const [key, descriptor] of originals) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor)
            else delete globalThis[key]
        }
        const absolute = path.resolve(cacheDir)
        assert.equal(path.dirname(absolute), path.resolve(tmpdir()))
        assert.ok(path.basename(absolute).startsWith('fullparty-richtext-vite-'))
        rmSync(absolute, { recursive: true, force: true })
    }
})
