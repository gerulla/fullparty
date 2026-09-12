import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'
import { parse, compileScript } from '@vue/compiler-sfc'
import { createI18n } from 'vue-i18n'
import * as workspaceData from '../../resources/js/utils/resourceWorkspaceData.ts'
import * as workspaceUtils from '../../resources/js/utils/resourceWorkspace.ts'
import { formatBytes } from '../../resources/js/utils/formatBytes.ts'

function evaluate(source, modules) {
    const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', compiled)(name => { assert.ok(name in modules, `Unexpected import: ${name}`); return modules[name] }, exports)
    return exports
}
const raw = (id, name, parent_id = null, sort_order = 0) => ({ id, name, parent_id, sort_order, slug: `folder-${id}` })

test('collection names allow exactly the requested ASCII letters, digits, spaces and punctuation', () => {
    const allowed = new Set('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 .-(){}[];_&')
    for (let code = 0; code < 128; code++) {
        const char = String.fromCharCode(code)
        assert.equal(workspaceData.isValidCollectionName(`A${char}B`), allowed.has(char), `ASCII ${code}`)
    }
    for (const name of ['Café', '攻略', 'Guide\u00a0Name', 'Guide\u200bName', 'Guide😀', '', '   ']) {
        assert.equal(workspaceData.isValidCollectionName(name), false, name)
    }
})

test('localized naming errors render punctuation literally and default names satisfy the rule', () => {
    for (const locale of ['en', 'de', 'fr', 'ja']) {
        const messages = JSON.parse(readFileSync(new URL(`../../lang/${locale}/groups/resources.json`, import.meta.url), 'utf8'))
        const i18n = createI18n({ legacy: false, locale, messages: { [locale]: messages } })
        assert.match(i18n.global.t('workspace.collection_name_invalid'), /\.\-\(\)\{\}\[\];_&/)
        assert.equal(workspaceData.isValidCollectionName(i18n.global.t('workspace.new_collection_name')), true)
        i18n.dispose()
    }
})
function harness({ initial = [raw(1, 'Root'), raw(2, 'Child', 1)], put, post, remove, create } = {}) {
    let collections = initial.map(workspaceData.workspaceCollection)
    const calls = []
    const module = evaluate(readFileSync(new URL('../../resources/js/composables/useResourceCollections.ts', import.meta.url), 'utf8'), {
        axios: { default: {
            isAxiosError: error => !!error.response,
            put: async (url, data) => { calls.push(['put', url, data]); return put ? put(url, data) : { data: { data: { ...initial.find(item => item.id === Number(url.id)), name: data.name } } } },
            post: async (url, data) => { calls.push(['post', url, data]); return post(url, data) },
            delete: async url => { calls.push(['delete', url]); return remove?.(url) },
        } },
        vue, 'ziggy-js': { route: (name, params) => ({ name, id: params.collection, group: params.group }) }, '@/utils/resourceWorkspaceData': workspaceData,
    })
    const actions = module.useResourceCollections({
        groupSlug: () => 'group', collections: () => collections, replace: next => { collections = next }, blocked: () => false, label: key => key === 'new_collection_name' ? 'New collection' : key,
        create: async (name, parentId) => { calls.push(['create', name, parentId]); return create ? create(name, parentId) : workspaceData.workspaceCollection(raw(10, name, parentId === null ? null : Number(parentId), 1)) },
        created: item => { calls.push(['created', item.id]) }, removed: item => { calls.push(['removed', item.id]) }, cancelled: () => { calls.push(['cancelled']) },
    })
    return { actions, calls, collections: () => collections }
}

test('root and child creation remain local until the inline name is saved', async () => {
    const { actions, calls, collections } = harness()
    actions.create('1')
    assert.equal(actions.state.editing.parentId, '1')
    assert.equal(calls.length, 0)
    actions.state.editing.name = ' Encounters '
    await actions.save()
    assert.deepEqual(calls[0], ['create', 'Encounters', '1'])
    assert.equal(collections().find(item => item.id === '10').parentId, '1')
    assert.equal(actions.state.editing, null)
    actions.create(null)
    assert.equal(actions.state.editing.parentId, null)
    actions.cancel()
    assert.equal(calls.filter(call => call[0] === 'create').length, 1)
})

test('rename sends only the name and preserves parent, slug, and sibling order', async () => {
    const { actions, calls, collections } = harness()
    actions.rename('2'); actions.state.editing.name = 'Renamed'
    await actions.save()
    assert.deepEqual(calls[0][2], { name: 'Renamed' })
    assert.equal(calls[0][1].name, 'groups.dashboard.resources.collections.update')
    assert.equal(collections().find(item => item.id === '2').parentId, '1')
    assert.equal(collections().find(item => item.id === '2').name, 'Renamed')
})

test('blank names and failed requests retain the inline input for correction', async () => {
    const { actions, collections } = harness({ put: async () => { throw { response: { data: { errors: { name: ['Name rejected'] } } } } } })
    actions.rename('2'); actions.state.editing.name = ' '
    await actions.save()
    assert.equal(actions.state.error, 'name_required')
    actions.state.editing.name = 'Retry me'
    await actions.save()
    assert.equal(actions.state.error, 'Name rejected')
    assert.equal(actions.state.editing.name, 'Retry me')
    assert.equal(collections().find(item => item.id === '2').name, 'Child')
    assert.equal(actions.state.busy, false)
})

test('invalid creation and rename names are rejected before any backend request', async () => {
    const { actions, calls, collections } = harness()
    for (const rename of [false, true]) {
        if (rename) actions.rename('2'); else actions.create(null)
        actions.state.editing.name = 'Builds / Loadouts'
        await actions.save()
        assert.equal(actions.state.error, 'collection_name_invalid')
        assert.equal(actions.state.editing.name, 'Builds / Loadouts')
        assert.equal(calls.some(call => ['create', 'put'].includes(call[0])), false)
        assert.equal(collections().find(item => item.id === '2').name, 'Child')
        actions.cancel()
    }
})

test('Enter followed by blur cannot submit a duplicate collection', async () => {
    let finish
    const { actions, calls } = harness({ create: () => new Promise(resolve => { finish = resolve }) })
    actions.create(null)
    const saving = actions.save()
    await actions.save()
    actions.cancel()
    assert.ok(actions.state.editing)
    assert.equal(calls.filter(call => call[0] === 'create').length, 1)
    finish(workspaceData.workspaceCollection(raw(10, 'New')))
    await saving
    assert.equal(actions.state.editing, null)
})

test('reordering applies authoritative sibling order without changing other branches', async () => {
    const { actions, calls, collections } = harness({ initial: [raw(1, 'A'), raw(2, 'B', null, 1), raw(3, 'Child', 1)], post: async () => ({ data: { data: [raw(2, 'B', null, 0), raw(1, 'A', null, 1)] } }) })
    await actions.reorder('2', -1)
    assert.deepEqual(calls[0][2], { offset: -1 })
    assert.deepEqual(collections().filter(item => item.parentId === null).map(item => item.id), ['2', '1'])
    assert.equal(collections().find(item => item.id === '3').parentId, '1')
    assert.deepEqual(workspaceUtils.buildWorkspaceTree(collections(), []).map(item => item.collection.id), ['2', '1', '3'])
})

test('failed reorders and non-empty deletions keep the original tree', async () => {
    const error = { response: { data: { errors: { collection: ['Move the contents first'] } } } }
    const { actions, collections } = harness({ post: async () => { throw error }, remove: async () => { throw error } })
    const before = structuredClone(collections())
    await actions.reorder('1', 1)
    assert.deepEqual(collections(), before)
    await actions.remove('1')
    assert.deepEqual(collections(), before)
    assert.equal(actions.state.error, 'Move the contents first')
})

test('changing an icon saves only the icon and applies the server response to the existing collection', async () => {
    const { actions, calls, collections } = harness({ put: async (_, data) => ({ data: { data: { ...raw(2, 'Child', 1, 4), icon: data.icon } } }) })
    actions.changeIcon('2')
    await actions.saveIcon('i-lucide-swords')
    assert.deepEqual(calls[0][2], { icon: 'i-lucide-swords' })
    assert.equal(actions.state.iconCollectionId, null)
    assert.deepEqual(collections().find(item => item.id === '2'), { id: '2', name: 'Child', parentId: '1', order: 4, icon: 'i-lucide-swords' })
    actions.changeIcon('2')
    await actions.saveIcon(null)
    assert.equal(collections().find(item => item.id === '2').icon, 'i-lucide-folder')
})

test('failed icon saves retain the picker and original icon so the selection can be retried', async () => {
    const { actions, collections } = harness({ put: async () => { throw { response: { data: { errors: { icon: ['Invalid icon'] } } } } } })
    actions.changeIcon('2')
    await actions.saveIcon('i-lucide-swords')
    assert.equal(actions.state.iconCollectionId, '2')
    assert.equal(actions.state.error, 'Invalid icon')
    assert.equal(collections().find(item => item.id === '2').icon, 'i-lucide-folder')
    actions.closeIconPicker()
    assert.equal(actions.state.iconCollectionId, null)
})

test('successful deletion removes only the selected empty collection', async () => {
    const { actions, calls, collections } = harness()
    await actions.remove('2')
    assert.deepEqual(collections().map(item => item.id), ['1'])
    assert.deepEqual(calls.at(-1), ['removed', '2'])
})

test('sidebar storage shows small uploads accurately and updates when usage or locale changes', () => {
    const file = new URL('../../resources/js/components/Groups/Resources/ResourceCollectionSidebar.vue', import.meta.url)
    const { descriptor } = parse(readFileSync(file, 'utf8'))
    const locale = vue.ref('en')
    const component = evaluate(compileScript(descriptor, { id: 'storage' }).content, {
        vue, 'vue-i18n': { useI18n: () => ({ t: key => key, locale }) },
        '@/utils/resourceWorkspace': workspaceUtils, '@/utils/formatBytes': { formatBytes },
        './ResourceCollectionNameInput.vue': { default: {} },
        '@/composables/useResourceTreeDrag': { useResourceTreeDrag: () => ({}) },
    }).default
    const workspace = vue.reactive({
        library: { visibility: 'public', storage: { used_bytes: 512, quota_bytes: 1024 ** 3 } },
        collectionActions: { state: { editing: null } }, state: { collections: [], resources: [], draft: null },
    })
    const vm = component.setup({ workspace }, { expose() {} })
    assert.equal(vm.storageText.value, '512 B / 1 GB')
    assert.equal(vm.storagePercent.value, 512 / 1024 ** 3 * 100)
    workspace.library.storage.used_bytes = 1536
    assert.equal(vm.storageText.value, '1.5 KB / 1 GB')
    locale.value = 'de'
    assert.equal(vm.storageText.value, '1,5 KB / 1 GB')
    workspace.library.storage.used_bytes = 0
    assert.equal(vm.storageText.value, '0 B / 1 GB')
})

test('folder dots, folder context menus, and empty-area menus use the intended actions and parent', () => {
    const calls = []
    const actions = {
        state: vue.reactive({ editing: null, busy: false, error: '' }),
        create: id => calls.push(['create', id]), rename: id => calls.push(['rename', id]),
        changeIcon: id => calls.push(['icon', id]),
        reorder: (id, offset) => calls.push(['reorder', id, offset]), remove: id => calls.push(['remove', id]),
    }
    const file = new URL('../../resources/js/components/Groups/Resources/ResourceCollectionSidebar.vue', import.meta.url)
    const { descriptor } = parse(readFileSync(file, 'utf8'))
    const source = compileScript(descriptor, { id: 'sidebar' }).content
    const component = evaluate(source, {
        vue, 'vue-i18n': { useI18n: () => ({ t: key => key, locale: vue.ref('en') }) }, '@/utils/resourceWorkspace': workspaceUtils,
        '@/utils/formatBytes': { formatBytes },
        './ResourceCollectionNameInput.vue': { default: {} },
        '@/composables/useResourceTreeDrag': { useResourceTreeDrag: () => ({}) },
    }).default
    const workspace = vue.reactive({ busy: false, createResource: id => calls.push(['resource', id]), collectionActions: actions, state: { collections: [raw(1, 'A'), raw(2, 'B', null, 1)].map(workspaceData.workspaceCollection), resources: [] } })
    const vm = component.setup({ workspace }, { expose() {} })
    const menu = vm.folderMenu('2')
    const contextMenu = vm.folderMenu('2', true)
    for (let index = 0; index < 500; index++) {
        assert.equal(vm.folderMenu('2'), menu, 'unchanged folder menus retain their items during drag renders')
        assert.equal(vm.folderMenu('2', true), contextMenu)
    }
    const dots = vm.folderMenu('2').flat()
    assert.deepEqual(dots.map(item => item.label.split('.').at(-1)), ['new_resource', 'rename', 'change_icon', 'move_up', 'move_down', 'delete'])
    assert.equal(dots[3].disabled, false)
    assert.equal(dots[4].disabled, true)
    dots[0].onSelect(); dots[1].onSelect(); dots[2].onSelect(); dots[3].onSelect(); dots[5].onSelect()
    vm.folderMenu('2', true)[0][0].onSelect()
    assert.equal(vm.emptyMenu.value.length, 1)
    vm.emptyMenu.value[0].onSelect()
    assert.deepEqual(calls, [['resource', '2'], ['rename', '2'], ['icon', '2'], ['reorder', '2', -1], ['remove', '2'], ['create', '2'], ['create', null]])
    workspace.state.collections[1].order = -1
    assert.notEqual(vm.folderMenu('2'), menu)
    assert.equal(vm.folderMenu('2').flat()[3].disabled, true)
    assert.equal(vm.folderMenu('2').flat()[4].disabled, false)
})
