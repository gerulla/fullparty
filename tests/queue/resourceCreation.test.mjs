import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { ref } from 'vue'
import * as workspaceData from '../../resources/js/utils/resourceWorkspaceData.ts'
import { collectionSlug, untitledResourcePayload, workspaceCollection, workspaceResource } from '../../resources/js/utils/resourceWorkspaceData.ts'

const emptyDocument = { type: 'doc', content: [{ type: 'paragraph' }] }
const snapshot = { title: 'Untitled resource', body: emptyDocument, access_level: 'everyone', tags: [], activity_type_ids: [], author: { name: 'Account' }, commands: [] }
const detail = { id: 42, collection_id: 5, slug: 'untitled-uuid', status: 'draft', sort_order: 0, updated_at: '2026-09-10T10:00:00Z', working_copy: snapshot, published: null, history: [] }

test('new resources use a real collection ID and a blank draft with no sample content', () => {
    const payload = untitledResourcePayload('5', 'Untitled resource', 'first')
    assert.equal(payload.collection_id, 5)
    assert.deepEqual(payload.content, { title: 'Untitled resource', slug: 'first', description: '', body: emptyDocument, access_level: 'everyone', tags: [], activity_type_ids: [], commands: [] })
    assert.notEqual(payload.content.slug, untitledResourcePayload('5', 'Untitled resource', 'second').content.slug)
})

test('server collection IDs and nesting are retained, using only folder icons', () => {
    assert.deepEqual(workspaceCollection({ id: 5, parent_id: 2, name: 'DRS', icon: 'custom', sort_order: 3 }), { id: '5', parentId: '2', name: 'DRS', icon: 'i-lucide-folder', order: 3 })
    assert.equal(workspaceCollection({ id: 2, parent_id: null, name: 'Root' }).parentId, null)
})

test('created drafts use server author and timestamps', () => {
    const resource = workspaceResource(detail, new Map())
    assert.equal(resource.id, '42')
    assert.equal(resource.collectionId, '5')
    assert.equal(resource.author, 'Account')
    assert.equal(resource.updatedAt, detail.updated_at)
    assert.equal(resource.status, 'draft')
    assert.deepEqual(resource.embeds, [])
    assert.deepEqual(resource.body, emptyDocument)
    assert.deepEqual(resource.history, [])
    assert.equal(resource.readerUrls, null)
})

test('resource reader links survive mapping independently of editable slug and access', () => {
    const reader_urls = { public: 'https://resources.fullparty.test/group/live', group: 'https://fullparty.test/en/groups/group/dashboard/resources/live' }
    const item = workspaceResource({ ...detail, reader_urls, working_copy: { ...snapshot, slug: 'unpublished-slug', access_level: 'admin' } }, new Map())
    assert.deepEqual(item.readerUrls, reader_urls)
    assert.equal(item.slug, 'unpublished-slug')
    assert.equal(item.access, 'admins')
})

test('summaries expose unpublished changes without bundling full document content', () => {
    const resource = workspaceResource({ ...detail, working_copy: undefined, summary: { ...snapshot, access_level: 'moderator' }, commands: [{ enabled: true, name: 'bridges' }], has_unpublished_changes: true }, new Map())
    assert.equal(resource.status, 'draft')
    assert.equal(resource.hasUnpublishedChanges, true)
    assert.equal(resource.access, 'moderators')
    assert.equal(resource.embeds[0].command, 'bridges')
})

test('selected resource details map native Discord fields, activity names, and managed images', () => {
    const resource = workspaceResource({ ...detail, working_copy: { ...snapshot, access_level: 'admin', activity_type_ids: [3], metadata_image_id: 'cover', commands: [{ name: 'bridges', enabled: true, embed: { color: 15, author: { name: 'Author', icon_url: 'https://example.com/avatar.png' }, image: { asset_id: 'image' }, fields: [{ name: 'Party', value: 'West', inline: true }] } }] } }, new Map([[3, 'DRS']]))
    assert.equal(resource.access, 'admins')
    assert.deepEqual(resource.activities, ['DRS'])
    assert.equal(resource.cover, '/resource-assets/cover')
    assert.equal(resource.embeds[0].color, '#00000f')
    assert.equal(resource.embeds[0].image, '/resource-assets/image')
    assert.equal(resource.embeds[0].authorIcon, 'https://example.com/avatar.png')
    assert.equal(resource.embeds[0].fields[0].inline, true)
})

test('collection slugs remain valid and unique for accented and non-Latin names', () => {
    assert.equal(collectionSlug('Équipement', 'uuid'), 'equipement-uuid')
    assert.equal(collectionSlug('攻略', 'uuid'), 'collection-uuid')
    assert.match(collectionSlug('A'.repeat(160), 'uuid'), /^[a-z0-9]+(?:-[a-z0-9]+)*$/)
    assert.ok(collectionSlug('A'.repeat(160), 'uuid').length <= 160)
})

function creationApi(axios) {
    const source = readFileSync(new URL('../../resources/js/composables/useResourceCreation.ts', import.meta.url), 'utf8')
    const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = { axios: { default: axios }, vue: { ref }, 'ziggy-js': { route: name => name }, '@/utils/resourceWorkspaceData': workspaceData }
    const exports = {}
    new Function('require', 'exports', compiled)(name => {
        assert.ok(name in modules, `Unexpected dependency: ${name}`)
        return modules[name]
    }, exports)
    return exports.useResourceCreation(() => 'group-slug', () => new Map())
}

test('repeated create clicks send only one POST and return the saved resource', async () => {
    let complete
    const calls = []
    const api = creationApi({ post: (url, payload) => { calls.push({ url, payload }); return new Promise(resolve => { complete = resolve }) } })
    const first = api.create('5', 'Untitled resource')
    assert.equal(api.creatingResource.value, true)
    assert.equal(await api.create('5', 'Untitled resource'), null)
    assert.equal(calls.length, 1)
    assert.equal(calls[0].url, 'groups.dashboard.resources.store')
    complete({ data: { data: detail } })
    assert.equal((await first).id, '42')
    assert.equal(api.creatingResource.value, false)
})

test('failed creation clears the busy state and permits a deliberate retry', async () => {
    let calls = 0
    const api = creationApi({ post: async () => { calls++; if (calls === 1) throw new Error('Validation failed'); return { data: { data: detail } } } })
    await assert.rejects(api.create('5', 'Untitled resource'), /Validation failed/)
    assert.equal(api.creatingResource.value, false)
    assert.equal((await api.create('5', 'Untitled resource')).id, '42')
    assert.equal(calls, 2)
})

test('collection creation posts the real parent ID once without updating it', async () => {
    let complete
    const calls = []
    const api = creationApi({ post: (url, payload) => { calls.push({ url, payload }); return new Promise(resolve => { complete = resolve }) } })
    const first = api.createCollection('Encounters', '2')
    assert.equal(api.creatingCollection.value, true)
    assert.equal(await api.createCollection('Encounters', '2'), null)
    assert.equal(calls.length, 1)
    assert.equal(calls[0].url, 'groups.dashboard.resources.collections.store')
    assert.equal(calls[0].payload.parent_id, 2)
    assert.equal(calls[0].payload.icon, 'i-lucide-folder')
    complete({ data: { data: { id: 5, parent_id: 2, name: 'Encounters' } } })
    assert.equal((await first).id, '5')
    assert.equal(api.creatingCollection.value, false)
})
