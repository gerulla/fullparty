import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { reactive, ref } from 'vue'

const image = (uuid = 'first') => ({ uuid, name: 'Positions.png', url: `/resource-assets/${uuid}`, alt_text: '', caption: null })
const page = items => ({ data: { data: items, current_page: 1, total: items.length, per_page: 24, last_page: 1 } })
function harness(axios, overrides = {}) {
    const source = readFileSync(new URL('../../resources/js/composables/useResourceImages.ts', import.meta.url), 'utf8')
    const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const hooks = []
    let changes = 0
    const context = { groupSlug: () => 'my-group', resourceId: () => '42', changed: () => changes++, ...overrides }
    const modules = {
        axios: { default: { isAxiosError: error => Boolean(error.response), ...axios } },
        vue: { reactive, ref, inject: () => context, onBeforeUnmount: fn => hooks.push(fn) },
        'vue-i18n': { useI18n: () => ({ t: key => key }) },
        'ziggy-js': { route: (name, params) => `${name}/${params.group}/${params.image ?? ''}` },
    }
    const exports = {}
    new Function('require', 'exports', compiled)(name => { assert.ok(name in modules); return modules[name] }, exports)
    const api = exports.useResourceImages()
    return { api, hooks, get changes() { return changes } }
}

test('image chooser requests searchable paginated images for its current resource', async () => {
    let request
    const { api } = harness({ get: async (...args) => { request = args; return page([image()]) } })
    api.state.query = 'bridge'; api.state.type = 'gif'
    await api.load(3)
    assert.match(request[0], /images.index\/my-group/)
    assert.deepEqual(request[1].params, { q: 'bridge', type: 'gif', page: 3, per_page: 24, resource_id: '42' })
    assert.equal(api.state.items[0].uuid, 'first')
    assert.equal(api.state.loading, false)
})

test('branding image requests use the library-only filter without an editor resource', async () => {
    let request
    const { api } = harness({ get: async (...args) => { request = args; return page([]) } }, { resourceId: undefined, libraryOnly: true })
    await api.load()
    assert.equal(request[1].params.library_only, 1)
    assert.equal(request[1].params.resource_id, undefined)
})

test('a late search result cannot replace the latest image list', async () => {
    const pending = []
    const { api } = harness({ get: () => new Promise(resolve => pending.push(resolve)) })
    const first = api.load(); const second = api.load()
    pending[1](page([image('new')]))
    await second
    pending[0](page([image('old')]))
    await first
    assert.equal(api.state.items[0].uuid, 'new')
})

test('failed image metadata edits preserve selection and explain validation errors', async () => {
    const { api } = harness({ put: async () => { throw { response: { data: { errors: { name: ['Enter a filename.'] } } } } } })
    api.selected.value = image()
    const success = await api.save(api.selected.value, { name: '', alt_text: '', caption: '' })
    assert.equal(success, false)
    assert.equal(api.selected.value.name, 'Positions.png')
    assert.equal(api.state.error, 'Enter a filename.')
    assert.equal(api.state.busy, false)
})

test('successful metadata edits update the selected image and tile', async () => {
    const { api } = harness({ put: async () => ({ data: { data: { ...image(), name: 'Bridges.png' } } }) })
    api.state.items = [image()]; api.selected.value = image()
    assert.equal(await api.save(api.selected.value, { name: 'Bridges.png', alt_text: '', caption: '' }), true)
    assert.equal(api.state.items[0].name, 'Bridges.png')
    assert.equal(api.selected.value.name, 'Bridges.png')
})

test('protected deletion retains the selected image and shows the backend error', async () => {
    const h = harness({ delete: async () => { throw { response: { data: { errors: { image: ['Image is in use.'] } } } } } })
    h.api.selected.value = image()
    assert.equal(await h.api.remove(), false)
    assert.equal(h.api.selected.value.uuid, 'first')
    assert.equal(h.api.state.error, 'Image is in use.')
    assert.equal(h.changes, 0)
})

test('deletion refreshes storage and returns to the previous page when needed', async () => {
    let requestedPage
    const h = harness({ delete: async () => {}, get: async (url, config) => { requestedPage = config.params.page; return page([]) } })
    h.api.selected.value = image(); h.api.state.items = [image()]; h.api.state.page = 2
    assert.equal(await h.api.remove(), true)
    assert.equal(h.api.selected.value, null)
    assert.equal(requestedPage, 1)
    assert.equal(h.changes, 1)
})

test('uploads from the library are persistent group uploads with image descriptions', async () => {
    let form
    const h = harness({ post: async (url, body) => { form = body; return { data: { data: { url: '/resource-assets/new' } } } } })
    const url = await h.api.upload(new File(['image'], 'diagram.gif', { type: 'image/gif' }), 'East and west', 'Bridge strategy')
    assert.equal(url, '/resource-assets/new')
    assert.equal(form.get('library_upload'), '1')
    assert.equal(form.get('resource_id'), null)
    assert.equal(form.get('alt_text'), 'East and west')
    assert.equal(form.get('caption'), 'Bridge strategy')
    assert.equal(h.changes, 1)
    assert.equal(h.api.state.busy, false)
})

test('unmounted image browsers ignore late responses', async () => {
    let resolve
    const h = harness({ get: () => new Promise(done => { resolve = done }) })
    const pending = h.api.load()
    h.hooks.forEach(fn => fn())
    resolve(page([image()]))
    await pending
    assert.equal(h.api.state.items.length, 0)
})
