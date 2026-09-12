import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'
import * as utils from '../../resources/js/utils/resourceWorkspace.ts'

function component(name) {
    const filename = new URL(`../../resources/js/components/Groups/Resources/${name}.vue`, import.meta.url).pathname
    const { descriptor } = parse(readFileSync(new URL(`../../resources/js/components/Groups/Resources/${name}.vue`, import.meta.url), 'utf8'), { filename })
    const script = compileScript(descriptor, { id: name })
    const template = compileTemplate({ source: descriptor.template.content, filename, id: name, compilerOptions: { bindingMetadata: script.bindings } })
    assert.deepEqual(template.errors, [])
    return { descriptor, script, template }
}

test('resource menu View is a native new-tab link and is disabled when no reader URL exists', () => {
    const { script } = component('ResourceLibraryBrowser')
    const modules = {
        vue, 'vue-i18n': { useI18n: () => ({ t: key => key, locale: vue.ref('en') }) },
        '@/utils/resourceWorkspace': utils,
        '@/composables/useResourceTreeDrag': { resourceDragType: 'resource' },
    }
    const code = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', code)(name => {
        assert.ok(name in modules, `Unexpected dependency: ${name}`)
        return modules[name]
    }, exports)
    const resource = { id: '1', collectionId: null, order: 0, readerUrl: 'https://resources.fullparty.test/group/guide' }
    const workspace = vue.reactive({
        state: { resources: [resource], checked: [], selectedId: null }, visibleResources: [resource], activities: [],
        viewUrl: item => item.readerUrl,
    })
    const vm = exports.default.setup({ workspace }, { expose() {} })
    const view = vm.menu(resource).find(item => item.label.endsWith('.view'))
    assert.deepEqual(view, {
        label: 'groups.resources.workspace.view', icon: 'i-lucide-external-link', to: resource.readerUrl,
        target: '_blank', rel: 'noopener noreferrer', disabled: false,
    })
    assert.equal(vm.menu({ ...resource, readerUrl: undefined }).find(item => item.label.endsWith('.view')).disabled, true)
})

test('resource view controls and public homepage shortcut use safe new-tab links instead of preview callbacks', () => {
    for (const name of ['ResourceLibraryInspector', 'ResourceWorkspaceInspector', 'ResourceEmbedPreview', 'ResourceCollectionSidebar']) {
        const { descriptor, template } = component(name)
        assert.match(template.code, /target: "_blank"/)
        assert.match(template.code, /rel: "noopener noreferrer"/)
        assert.doesNotMatch(descriptor.template.content, /workspace\.preview|\$emit\('open'\)/)
    }
    const { descriptor } = component('ResourceCollectionSidebar')
    assert.match(descriptor.template.content, /v-if="isPublic && workspace\.library\?\.public_url"/)
    assert.match(descriptor.template.content, /:to="workspace\.library\.public_url"/)
    assert.match(descriptor.template.content, /:aria-label="l\('view_public_library'\)"/)
    assert.doesNotMatch(component('ResourceWorkspace').descriptor.template.content, /state\.previewOpen/)
})
