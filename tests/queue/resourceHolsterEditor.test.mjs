import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { parse, compileScript } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { renderToString } from '@vue/server-renderer'
import { validateResourceFields } from '../../resources/js/utils/resourceValidation.ts'

function editor() {
    const source = readFileSync(new URL('../../resources/js/components/Groups/Resources/ResourceDocumentEditor.vue', import.meta.url), 'utf8')
    const { descriptor } = parse(source)
    const script = compileScript(descriptor, { id: 'holster-editor-test', inlineTemplate: true })
    const code = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = {
        vue, 'vue-i18n': { useI18n: () => ({ t: key => key }) },
        '@/Types/ResourceContent': { resourceContentKey: Symbol('content') },
        './resourceContentExtensions': { resourceContentExtensions: () => [] },
        './ResourceContentTools.vue': { default: () => null },
        './ResourceImageLibraryModal.vue': { default: () => null },
        '@/composables/useResourceImageUpload': { useResourceImageUpload: () => async () => '' },
        '@/components/Shared/RichText/RichTextReader.vue': { default: () => vue.h('article', { 'data-reader': true }) },
        '@/components/Shared/RichText/RichTextEditor.vue': { default: () => vue.h('div', { 'data-editor': true }) },
    }
    const exports = {}
    new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name] }, exports)
    return exports.default
}

function workspace(holsterId) {
    const draft = {
        title: 'Inherited title', description: 'Inherited description', body: { type: 'doc', content: [] },
        access: 'everyone', activities: ['DRS'], activityTypeIds: [1], tags: ['tank'], embeds: [], cover: '',
    }
    return { state: { draft, resources: [] }, selected: { id: '1', holsterId }, accessLevels: ['everyone'], activityOptions: [{ value: 1, label: 'DRS' }], fieldError: () => undefined }
}

async function render(workspace) {
    const app = vue.createSSRApp(editor(), { workspace, holsterEditUrl: '/manage-holsters' })
    const wrapper = { setup: (_, { slots }) => () => vue.h('div', slots.default?.()) }
    for (const name of ['UFormField', 'UAlert', 'UButton']) app.component(name, wrapper)
    for (const name of ['UInput', 'UTextarea', 'UInputTags', 'USelect', 'USelectMenu']) {
        app.component(name, { props: ['modelValue', 'items', 'ui'], setup: (props, { attrs }) => () => vue.h('input', { ...attrs, value: props.modelValue }) })
    }
    return renderToString(app)
}

test('linked holster content renders read-only while resource metadata remains editable', async () => {
    const html = await render(workspace(7))
    assert.match(html, /readonly[^>]*aria-label="groups.resources.workspace.title"/)
    assert.match(html, /readonly[^>]*aria-label="groups.resources.workspace.description"/)
    assert.match(html, /disabled[^>]*aria-label="groups.resources.workspace.activities"/)
    assert.match(html, /data-reader/)
    assert.doesNotMatch(html, /data-editor/)
    for (const label of ['access', 'tags']) {
        const input = html.match(new RegExp(`<input[^>]*aria-label="groups.resources.workspace.${label}"[^>]*>`))[0]
        assert.doesNotMatch(input, /disabled|readonly/)
    }
})

test('ordinary resource content retains the WYSIWYG editor and editable title and description', async () => {
    const html = await render(workspace(null))
    assert.match(html, /data-editor/)
    assert.doesNotMatch(html, /readonly|data-reader/)
})

test('long inherited holster notes do not prevent saving tags but normal resource limits remain enforced', () => {
    const draft = { ...workspace(7).state.draft, description: 'x'.repeat(5000) }
    assert.deepEqual(validateResourceFields(draft, [{ id: '1', holsterId: 7 }], '1', key => key), {})
    assert.ok(validateResourceFields(draft, [{ id: '1' }], '1', key => key).description)
})
