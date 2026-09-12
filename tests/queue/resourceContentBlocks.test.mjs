import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { JSDOM } from 'jsdom'
import ts from 'typescript'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parseResourceVideo, resourceVideoPlayer } from '../../resources/js/utils/resourceVideo.ts'
import { resourceContentKey } from '../../resources/js/Types/ResourceContent.ts'
import { readerDate } from '../../resources/js/utils/resourceReader.ts'

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://resources.fullparty.test' })
for (const key of ['window', 'document', 'navigator', 'Node', 'HTMLElement', 'Element', 'SVGElement', 'MutationObserver', 'DOMParser', 'getComputedStyle']) Object.defineProperty(globalThis, key, { configurable: true, value: key === 'getComputedStyle' ? dom.window.getComputedStyle.bind(dom.window) : dom.window[key] })
globalThis.requestAnimationFrame = callback => setTimeout(callback, 0)
globalThis.cancelAnimationFrame = clearTimeout
const vue = await import('vue')
const tiptap = await import('@tiptap/vue-3')
const core = await import('@tiptap/core')
const { default: StarterKit } = await import('@tiptap/starter-kit')
const width = vue.ref(720)
const labels = JSON.parse(readFileSync(new URL('../../lang/en/groups/resources.json', import.meta.url), 'utf8')).content
const i18n = { useI18n: () => ({ locale: vue.ref('en'), t: (key, params = {}) => Object.entries(params).reduce((value, [name, replacement]) => value.replace(`{${name}}`, replacement), labels[key.split('.').at(-1)] ?? key) }) }
const cache = new Map()
function evaluate(source, modules) {
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name] }, exports)
    return exports
}
function component(name, inlineTemplate = true, overrides = {}) {
    if (inlineTemplate && cache.has(name)) return cache.get(name)
    const file = new URL(`../../resources/js/components/Groups/Resources/${name}.vue`, import.meta.url)
    const { descriptor } = parse(readFileSync(file, 'utf8'))
    const script = compileScript(descriptor, { id: name, inlineTemplate })
    const modules = {
        vue, 'vue-i18n': i18n, '@tiptap/vue-3': tiptap,
        '@vueuse/core': { useElementSize: () => ({ width }) },
        '@inertiajs/vue3': { Link: vue.defineComponent({ props: ['href'], setup: (props, { slots }) => () => vue.h('a', { href: props.href }, slots.default?.()) }) },
        '@/Types/ResourceContent': { resourceContentKey }, '@/utils/resourceVideo': { parseResourceVideo, resourceVideoPlayer }, '@/utils/resourceReader': { readerDate },
        ...overrides,
    }
    for (const dependency of ['ResourceReaderRow', 'ResourceVideoPlayer']) {
        if (script.content.includes(`./${dependency}.vue`)) modules[`./${dependency}.vue`] = { default: component(dependency) }
    }
    const result = evaluate(script.content, modules).default
    if (inlineTemplate) cache.set(name, result)
    return result
}
function extensions() {
    const file = new URL('../../resources/js/components/Groups/Resources/resourceContentExtensions.ts', import.meta.url)
    return evaluate(readFileSync(file, 'utf8'), { '@tiptap/core': core, '@tiptap/vue-3': tiptap, '@/components/Groups/Resources/ResourceContentNode.vue': { default: component('ResourceContentNode') } }).resourceContentExtensions()
}
function mount(render, context) {
    const element = document.createElement('div'); document.body.append(element)
    const app = vue.createApp({ setup() { if (context) vue.provide(resourceContentKey, context); return render } })
    app.component('UIcon', { props: ['name'], setup: props => () => vue.h('span', { 'data-icon': props.name }) })
    app.component('UButton', { props: ['icon'], setup: (props, { attrs }) => () => vue.h('button', attrs, props.icon) })
    app.mount(element)
    return { element, close: () => { app.unmount(); element.remove() } }
}

test('video URL parsing matches server fixtures and builds provider-controlled player URLs', () => {
    const fixtures = JSON.parse(readFileSync(new URL('../Fixtures/resource-video-urls.json', import.meta.url), 'utf8'))
    for (const [input, expected] of fixtures) assert.equal(parseResourceVideo(input)?.url ?? null, expected, input)
    for (const url of ['https://twitch.tv/fullparty', 'https://twitch.tv/videos/123?t=30s', 'https://clips.twitch.tv/TestClip']) {
        const player = new URL(resourceVideoPlayer(parseResourceVideo(url), 'resources.fullparty.test'))
        assert.equal(player.searchParams.get('parent'), 'resources.fullparty.test')
        assert.equal(player.searchParams.get('autoplay'), 'true')
        assert.ok(['player.twitch.tv', 'clips.twitch.tv'].includes(player.hostname))
    }
})

test('resource and video blocks insert at the selection, survive reload, update and undo', () => {
    const editor = new core.Editor({ element: document.createElement('div'), extensions: [StarterKit, ...extensions()], content: '<p>Before</p><p>After</p>', enableContentCheck: true })
    editor.commands.setTextSelection(9)
    editor.commands.insertContent({ type: 'resourceLink', attrs: { resourceId: 'ae5a9e25-1dab-4d41-a31f-2e1ed842442b' } })
    assert.equal(editor.getJSON().content[1].type, 'resourceLink')
    editor.commands.insertContent({ type: 'videoEmbed', attrs: { url: 'https://twitch.tv/fullparty', title: 'Stream' } })
    const saved = editor.getJSON()
    const reloaded = new core.Editor({ element: document.createElement('div'), extensions: [StarterKit, ...extensions()], content: saved, enableContentCheck: true })
    assert.deepEqual(reloaded.getJSON(), saved)
    let position
    editor.state.doc.descendants((node, pos) => { if (node.type.name === 'videoEmbed') position = pos })
    editor.commands.setNodeSelection(position)
    editor.commands.insertContent({ type: 'videoEmbed', attrs: { url: 'https://twitch.tv/fullparty', title: 'Updated' } })
    assert.equal(editor.getJSON().content.filter(node => node.type === 'videoEmbed').length, 1)
    assert.equal(editor.getJSON().content.find(node => node.type === 'videoEmbed').attrs.title, 'Updated')
    assert.equal(editor.commands.undo(), true)
    editor.destroy(); reloaded.destroy()
})

test('reader node views render live resource rows, hide unavailable targets and load video only on click', async () => {
    const resources = vue.ref([{ id: 1, slug: 'ae5a9e25-1dab-4d41-a31f-2e1ed842442b', title: 'Strategy guide', description: 'Prepare for the encounter', tags: ['raid'], access_level: 'everyone' }])
    const editor = new tiptap.Editor({ extensions: [StarterKit, ...extensions()], editable: false, content: { type: 'doc', content: [
        { type: 'resourceLink', attrs: { resourceId: resources.value[0].slug } },
        { type: 'videoEmbed', attrs: { url: 'https://youtu.be/dQw4w9WgXcQ', title: 'Encounter walkthrough' } },
    ] } })
    const view = mount(() => vue.h(tiptap.EditorContent, { editor }), { resources: vue.computed(() => resources.value), href: () => '/group/resource' })
    await vue.nextTick(); await vue.nextTick()
    assert.match(view.element.textContent, /Strategy guide/)
    assert.equal(view.element.querySelector('.resource-reader-row').getAttribute('href'), '/group/resource')
    assert.equal(view.element.querySelector('iframe'), null)
    view.element.querySelector('button[aria-label="Play Encounter walkthrough"]').click()
    await vue.nextTick()
    assert.match(view.element.querySelector('iframe').src, /^https:\/\/www.youtube-nocookie.com\/embed\/dQw4w9WgXcQ/)
    resources.value = []
    await vue.nextTick()
    assert.doesNotMatch(view.element.textContent, /Strategy guide/)
    assert.match(view.element.textContent, /This resource is unavailable/)
    view.close(); editor.destroy()
})

test('editor controls search cards and restore the insertion position after using a modal', () => {
    const resourceId = 'ae5a9e25-1dab-4d41-a31f-2e1ed842442b'
    const context = { resources: vue.computed(() => [
        { slug: resourceId, title: 'Strategy', description: 'Party positions', tags: ['Savage'] },
        { slug: 'current', title: 'Current document', description: '', tags: [] },
    ]), href: () => '' }
    const editor = new core.Editor({ element: document.createElement('div'), extensions: [StarterKit, ...extensions()], content: '<p>Before</p><p>After</p>' })
    const vm = component('ResourceContentTools', false, { vue: { ...vue, inject: () => context } }).setup({ editor, currentResourceId: 'current' }, { expose() {} })
    assert.equal(vm.resources.value.length, 1)
    for (const term of ['strategy', 'positions', 'SAVAGE', `https://resources.fullparty.test/group/${resourceId}`]) {
        vm.query.value = term
        assert.equal(vm.resources.value.length, 1, term)
    }
    editor.commands.setTextSelection(9)
    vm.open('resource')
    editor.commands.setTextSelection(1)
    vm.insert({ type: 'resourceLink', attrs: { resourceId } })
    assert.equal(editor.getJSON().content[1].type, 'resourceLink')
    assert.equal(vm.resourceOpen.value, false)
    editor.commands.insertContent({ type: 'videoEmbed', attrs: { url: 'https://twitch.tv/fullparty', title: 'Original' } })
    let position
    editor.state.doc.descendants((node, pos) => { if (node.type.name === 'videoEmbed') position = pos })
    editor.commands.setNodeSelection(position)
    vm.open('video')
    assert.equal(vm.title.value, 'Original')
    vm.insert({ type: 'videoEmbed', attrs: { url: 'https://twitch.tv/fullparty', title: 'Revised' } })
    assert.equal(editor.getJSON().content.filter(node => node.type === 'videoEmbed').length, 1)
    assert.equal(editor.getJSON().content.find(node => node.type === 'videoEmbed').attrs.title, 'Revised')
    editor.destroy()
})

test('Twitch uses an external watch action when the article is narrower than its minimum player width', async () => {
    width.value = 350
    const view = mount(() => vue.h(component('ResourceVideoPlayer'), { url: 'https://twitch.tv/fullparty', title: 'Live raid' }))
    assert.equal(view.element.querySelector('iframe'), null)
    assert.match(view.element.textContent, /Watch on Twitch/)
    width.value = 720
    await vue.nextTick()
    view.element.querySelector('button[aria-label="Play Live raid"]').click()
    await vue.nextTick()
    assert.equal(new URL(view.element.querySelector('iframe').src).searchParams.get('parent'), 'resources.fullparty.test')
    view.close()
})
