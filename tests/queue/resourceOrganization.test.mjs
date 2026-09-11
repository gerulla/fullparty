import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'
import { parse, compileScript } from '@vue/compiler-sfc'
import * as utils from '../../resources/js/utils/resourceWorkspace.ts'
import * as data from '../../resources/js/utils/resourceWorkspaceData.ts'
import { resourceSavePayload } from '../../resources/js/utils/resourceSavePayload.ts'

function evaluate(source, modules, globals = {}) {
    const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', ...Object.keys(globals), js)(name => {
        assert.ok(name in modules, `Unexpected dependency: ${name}`)
        return modules[name]
    }, exports, ...Object.values(globals))
    return exports
}
const load = path => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8')
const snapshot = { title: 'Guide', slug: 'guide', access_level: 'everyone', author: { name: 'Author' }, tags: [], activity_type_ids: [] }
const resource = (id, parent = null, extra = {}) => data.workspaceResource({ id, collection_id: parent, slug: 'guide', status: 'draft', version: 1, sort_order: 0, updated_at: '', summary: snapshot, ...extra }, new Map())

test('root IDs remain null in editor documents, creation, saves and published snapshots', () => {
    const item = resource(1)
    assert.equal(item.collectionId, null)
    assert.equal(data.untitledResourcePayload(null, 'Untitled', 'uuid').collection_id, null)
    assert.equal(resourceSavePayload(item, item, '', false).collection_id, null)
    assert.equal(utils.cloneDocument(item).collectionId, null)
})

test('optimistic move plans reject cycles and protected resources without mutating their inputs', () => {
    const collections = [{ id: '5', parentId: null, order: 0 }, { id: '6', parentId: '5', order: 1 }]
    const resources = [resource(1, null, { is_home: true }), resource(2, 5), resource(3, 5, { has_unpublished_changes: true })]
    const before = JSON.stringify({ collections, resources })
    assert.equal(utils.workspaceMovePositions(collections, resources, 'collection', '5', '6'), null)
    assert.equal(utils.workspaceMovePositions(collections, resources, 'resource', '1', '5'), null)
    assert.deepEqual(utils.workspaceMovePositions(collections, resources, 'resource', '3', null), [{ id: '3', parentId: null, order: 0 }])
    assert.equal(utils.workspaceMovePositions(collections, resources, 'resource', '2', 'missing'), null)
    assert.equal(utils.workspaceMovePositions(collections, resources, 'resource', '2', null, '1'), null)
    assert.deepEqual(utils.workspaceMovePositions(collections, resources, 'resource', '2', null), [{ id: '2', parentId: null, order: 0 }])
    assert.equal(JSON.stringify({ collections, resources }), before)
})

test('blank tree space handles root drops without a visible root target', () => {
    const { descriptor } = parse(load('components/Groups/Resources/ResourceCollectionSidebar.vue'))
    assert.match(descriptor.template.content, /class="collection-tree"[^>]+@dragover\.self="drag.over\(\$event\)"[^>]+@drop\.self="drag.drop\(\$event\)"/)
    assert.doesNotMatch(descriptor.template.content, /root-drop-target|l\('root'\)/)
})

test('Home remains first above folders and ordinary root resources', () => {
    const home = resource(1, null, { is_home: true, sort_order: 100 })
    const folders = [{ id: '5', name: 'Folder', parentId: null }]
    const items = [resource(2), home, resource(3, 5)]
    const tree = utils.buildWorkspaceTree(folders, items)
    assert.equal(tree[0].resource.id, '1')
    assert.equal(tree.filter(item => item.kind === 'resource' && item.resource.isHome).length, 1)
    assert.equal(utils.filterWorkspaceResources(items, folders, { scope: 'all', query: '', status: 'all', access: 'all', activity: 'all' })[0].id, '1')
})

function dragHarness() {
    const calls = []
    const metrics = { descendantScans: 0, layoutReads: 0 }
    const workspace = vue.reactive({
        busy: false, collectionActions: { state: { editing: null } },
        state: { collections: [{ id: '5', parentId: null }, { id: '6', parentId: '5' }, { id: '7', parentId: null }], resources: [resource(1, null, { is_home: true }), resource(2, 5), resource(3, 5), resource(4, 5, { has_unpublished_changes: true })] },
        organize: (...args) => calls.push(args),
    })
    const module = evaluate(load('composables/useResourceTreeDrag.ts'), { vue, '@/utils/resourceWorkspace': {
        ...utils, collectionDescendants: (...args) => { metrics.descendantScans++; return utils.collectionDescendants(...args) },
    } })
    const drag = module.useResourceTreeDrag(workspace)
    const values = new Map()
    const event = (y = 20) => ({ clientY: y, preventDefault() { this.prevented = true }, currentTarget: { getBoundingClientRect: () => { metrics.layoutReads++; return { top: 0, height: 34 } } }, dataTransfer: { types: [...values.keys()], setData: (key, value) => values.set(key, value), getData: key => values.get(key) } })
    const folder = id => ({ kind: 'collection', collection: workspace.state.collections.find(item => item.id === id) })
    const file = id => ({ kind: 'resource', resource: workspace.state.resources.find(item => item.id === id) })
    return { calls, workspace, drag, event, folder, file, metrics }
}

test('a prolonged drag only updates highlights when the drop destination changes', async () => {
    const { drag, event, folder, calls, metrics } = dragHarness()
    drag.start(event(), folder('7'))
    let updates = 0
    const stop = vue.watchEffect(() => { drag.classes(folder('5')); drag.classes(); updates++ })
    try {
        for (let index = 0; index < 500; index++) {
            const hover = event(15 + index % 10)
            drag.over(hover, folder('5'))
            assert.equal(hover.prevented, true)
            assert.equal(hover.dataTransfer.dropEffect, 'move')
            await vue.nextTick()
        }
        assert.equal(updates, 2, 'initial render and first highlight, not one render per dragover')
        assert.equal(metrics.descendantScans, 1, 'ancestry is reused during the drag')
        assert.equal(calls.length, 0, 'hovering never saves or reorders')
        drag.over(event(2), folder('5')); await vue.nextTick()
        assert.deepEqual(drag.classes(folder('5')), { 'drop-inside': false, 'drop-before': true })
        assert.equal(updates, 3)
        drag.over(event()); await vue.nextTick()
        assert.deepEqual(drag.classes(), { 'drop-inside': true, 'drop-before': false })
        assert.equal(updates, 4)
        drag.end(); await vue.nextTick()
        assert.equal(updates, 5)
    } finally { stop() }
})

test('resource drags do not measure folder geometry to decide a drop destination', () => {
    const { drag, event, folder, file, metrics } = dragHarness()
    drag.start(event(), file('2'))
    for (let index = 0; index < 500; index++) drag.over(event(), folder('7'))
    assert.equal(metrics.layoutReads, 0)
    assert.equal(metrics.descendantScans, 0)
})

test('cached drag validation refreshes when the tree or resource restrictions change', () => {
    const { drag, event, folder, file, workspace, calls } = dragHarness()
    drag.start(event(), folder('7'))
    drag.over(event(), folder('5'))
    assert.equal(drag.classes(folder('5'))['drop-inside'], true)
    workspace.state.collections.find(item => item.id === '5').parentId = '7'
    drag.over(event(), folder('5'))
    assert.equal(drag.classes(folder('5'))['drop-inside'], false)
    drag.drop(event(), folder('5'))
    assert.equal(calls.length, 0)
    drag.start(event(), file('2'))
    drag.over(event(), folder('7'))
    assert.equal(drag.classes(folder('7'))['drop-inside'], true)
    workspace.state.resources.find(item => item.id === '2').isHome = true
    drag.over(event(), folder('7'))
    assert.equal(drag.classes(folder('7'))['drop-inside'], false)
    drag.drop(event(), folder('7'))
    assert.equal(calls.length, 0)
})

test('folder drops nest collections and their top edges reorder siblings', () => {
    const { drag, event, folder, calls } = dragHarness()
    drag.start(event(), folder('7')); drag.drop(event(), folder('5'))
    assert.deepEqual(calls.pop(), ['collection', '7', '5', null])
    drag.start(event(), folder('7')); drag.drop(event(2), folder('5'))
    assert.deepEqual(calls.pop(), ['collection', '7', null, '5'])
    drag.start(event(), folder('6')); drag.drop(event())
    assert.deepEqual(calls.pop(), ['collection', '6', null, null])
})

test('resources drop into folders, before files, or at the library root', () => {
    const { drag, event, folder, file, calls } = dragHarness()
    drag.start(event(), file('2')); drag.drop(event(), folder('7'))
    assert.deepEqual(calls.pop(), ['resource', '2', '7', null])
    drag.start(event(), file('2')); drag.drop(event(), file('3'))
    assert.deepEqual(calls.pop(), ['resource', '2', '5', '3'])
    drag.start(event(), file('2')); drag.drop(event())
    assert.deepEqual(calls.pop(), ['resource', '2', null, null])
})

test('cycles, Home, self drops and unrelated payloads cannot move but drafts can', () => {
    const { drag, event, folder, file, calls } = dragHarness()
    assert.equal(drag.canDrag(file('1')), false)
    assert.equal(drag.canDrag(file('4')), true)
    drag.start(event(), folder('5')); drag.drop(event(), folder('6'))
    drag.start(event(), file('2')); drag.drop(event(), file('2'))
    drag.start(event(), file('2')); drag.drop(event(), file('1'))
    drag.drop({ ...event(), dataTransfer: { getData: () => 'not JSON' } })
    assert.equal(calls.length, 0)
})

test('embed child items cannot be dragged or used as move destinations', () => {
    const { drag, event, file, calls } = dragHarness()
    const embed = { kind: 'embed', resource: file('2').resource, embed: { command: 'west' }, index: 0, depth: 1 }
    assert.equal(drag.canDrag(embed), false)
    const start = event()
    drag.start(start, embed)
    assert.equal(start.prevented, true)
    drag.start(event(), file('3'))
    drag.drop(event(), embed)
    assert.equal(calls.length, 0)
})

test('inline names focus and select after menu teardown, and expose a focus handoff', async () => {
    const { descriptor } = parse(load('components/Groups/Resources/ResourceCollectionNameInput.vue'))
    let mount, unmount, exposed
    const frames = []; const calls = []
    const component = evaluate(compileScript(descriptor, { id: 'name' }).content, {
        vue: { ...vue, onMounted: callback => { mount = callback }, onBeforeUnmount: callback => { unmount = callback } },
        'vue-i18n': { useI18n: () => ({ t: key => key }) },
    }, { requestAnimationFrame: callback => { frames.push(callback); return frames.length }, cancelAnimationFrame: () => {} }).default
    const vm = component.setup({ actions: { state: { editing: { name: 'Rename me', id: '5' } }, save: () => calls.push('save') } }, { expose: value => { exposed = value } })
    vm.input.value = { inputRef: { focus: () => calls.push('focus'), select: () => calls.push('select'), scrollIntoView: () => calls.push('scroll') } }
    await mount(); vm.blur()
    assert.deepEqual(calls, [])
    frames.shift()(); frames.shift()()
    assert.deepEqual(calls, ['focus', 'select', 'scroll'])
    exposed.focus(); vm.blur()
    assert.deepEqual(calls.slice(-4), ['focus', 'select', 'scroll', 'save'])
    unmount()
})
