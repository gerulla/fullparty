import assert from 'node:assert/strict'
import test from 'node:test'
import { buildWorkspaceTree, cloneDocument, collectionDescendants, filterWorkspaceResources, publishWorkspaceResource, validateWorkspaceDocument, workspaceCollectionPath } from '../../resources/js/utils/resourceWorkspace.ts'

const collections = [
    { id: 'drs', parentId: null, name: 'DRS' },
    { id: 'encounters', parentId: 'drs', name: 'Encounters' },
    { id: 'bridges', parentId: 'encounters', name: 'Bridges' },
    { id: 'ba', parentId: null, name: 'BA' },
]
function resource(overrides = {}) {
    return { id: 'one', title: 'DRS Bridges', description: 'Raid preparation', body: '## Plan',
        collectionId: 'bridges', access: 'everyone', activities: ['DRS'], tags: ['strategy'],
        author: 'Faust', cover: '', status: 'draft', order: 0, history: [], published: null,
        embed: { enabled: true, command: 'bridges', fields: [{ name: 'Team', value: 'North' }] }, ...overrides }
}
function filter(resources, overrides = {}) {
    return filterWorkspaceResources(resources, collections, { scope: 'all', query: '', status: 'all', access: 'all', activity: 'all', ...overrides })
}
test('collection scope includes all nested descendants, not other branches', () => {
    assert.deepEqual(collectionDescendants(collections, 'drs'), ['drs', 'encounters', 'bridges'])
    assert.deepEqual(filter([resource(), resource({ id: 'two', collectionId: 'ba' })], { scope: 'drs' }).map(item => item.id), ['one'])
})
test('malformed collection cycles terminate', () => {
    assert.deepEqual(collectionDescendants([{ id: 'a', parentId: 'b' }, { id: 'b', parentId: 'a' }], 'a'), ['a', 'b'])
})
test('sidebar nests resource files beneath their collections and retains manual ordering', () => {
    const resources = [resource({ id: 'later', order: 2 }), resource({ id: 'first', order: 1 }), resource({ id: 'ba-file', collectionId: 'ba' })]
    const rows = buildWorkspaceTree(collections, resources)
    assert.deepEqual(rows.map(item => [item.kind, item.kind === 'collection' ? item.collection.id : item.resource.id, item.depth]), [
        ['collection', 'drs', 0], ['collection', 'encounters', 1], ['collection', 'bridges', 2],
        ['resource', 'first', 3], ['resource', 'later', 3], ['collection', 'ba', 0], ['resource', 'ba-file', 1],
    ])
    assert.equal(rows[0].count, 2)
    assert.equal(rows[2].hasChildren, true)
    assert.deepEqual(resources.map(item => item.id), ['later', 'first', 'ba-file'])
})
test('collapsing a collection hides all descendant folders and files while keeping its count', () => {
    const rows = buildWorkspaceTree(collections, [resource()], ['drs'])
    assert.deepEqual(rows.map(item => item.collection.id), ['drs', 'ba'])
    assert.equal(rows[0].count, 1)
    assert.equal(rows[0].hasChildren, true)
    assert.equal(rows[1].hasChildren, false)
})
test('collections containing only files can collapse and unfiled resources remain at the root', () => {
    const rows = buildWorkspaceTree(collections, [resource({ collectionId: 'ba' }), resource({ id: 'unfiled', collectionId: null })], ['drs', 'ba'])
    assert.equal(rows[1].hasChildren, true)
    assert.equal(rows[1].count, 1)
    assert.equal(rows[2].kind, 'resource')
    assert.equal(rows[2].resource.id, 'unfiled')
    assert.equal(rows[2].depth, 0)
})
test('files move to their current collection without retaining a stale sidebar entry', () => {
    const source = resource({ collectionId: 'ba' })
    source.collectionId = 'encounters'
    const rows = buildWorkspaceTree(collections, [source])
    const files = rows.filter(item => item.kind === 'resource')
    assert.equal(files.length, 1)
    assert.equal(files[0].depth, 2)
    assert.equal(rows.find(item => item.kind === 'collection' && item.collection.id === 'ba').count, 0)
})
test('collection breadcrumb includes every parent in order', () => {
    assert.equal(workspaceCollectionPath(collections, 'bridges'), 'DRS / Encounters / Bridges')
    assert.equal(workspaceCollectionPath(collections, null), '')
    assert.equal(workspaceCollectionPath(collections, 'missing'), '')
})
test('collection breadcrumb safely stops at a cycle', () => {
    assert.equal(workspaceCollectionPath([{ id: 'a', parentId: 'b', name: 'A' }, { id: 'b', parentId: 'a', name: 'B' }], 'a'), 'B / A')
})
test('search matches title, tags, activity and collection case-insensitively', () => {
    for (const query of [' DRS ', 'STRATEGY', 'bridges', 'raid preparation']) assert.equal(filter([resource()], { query }).length, 1)
    assert.equal(filter([resource()], { query: 'not present' }).length, 0)
})
test('filters combine and results follow the explicit ordering', () => {
    const rows = [resource({ id: 'one', order: 2 }), resource({ id: 'two', order: 1 }), resource({ id: 'three', access: 'admins' })]
    assert.deepEqual(filter(rows, { access: 'everyone', status: 'draft', activity: 'DRS' }).map(item => item.id), ['two', 'one'])
    assert.equal(filter(rows, { activity: 'BA' }).length, 0)
})
test('draft and pending shortcuts only include their status', () => {
    const rows = [resource(), resource({ id: 'pending', status: 'pending' }), resource({ id: 'live', status: 'published' })]
    assert.deepEqual(filter(rows, { scope: 'drafts' }).map(item => item.id), ['one'])
    assert.deepEqual(filter(rows, { scope: 'pending' }).map(item => item.id), ['pending'])
})
test('draft snapshots are independent, without lifecycle or history fields', () => {
    const source = resource()
    const snapshot = cloneDocument(source)
    snapshot.embed.fields[0].value = 'South'
    snapshot.tags.push('new')
    assert.equal(source.embed.fields[0].value, 'North')
    assert.deepEqual(source.tags, ['strategy'])
    assert.equal('history' in snapshot, false)
    assert.equal('published' in snapshot, false)
})
test('publication promotes only pending revisions and snapshots their content', () => {
    const source = resource({ status: 'pending' })
    const result = publishWorkspaceResource(source)
    assert.equal(result.status, 'published')
    assert.equal(source.status, 'pending')
    result.body = 'Later edit'
    assert.equal(result.published.body, '## Plan')
    const draft = resource()
    assert.strictEqual(publishWorkspaceResource(draft), draft)
})
test('resource title is required even without an embed', () => {
    assert.equal(validateWorkspaceDocument(resource({ title: ' ', embed: { enabled: false } }), [], 'one'), 'title_required')
})
test('commands validate their syntax and group-wide uniqueness', () => {
    const source = resource()
    assert.equal(validateWorkspaceDocument(source, [source], source.id), null)
    assert.equal(validateWorkspaceDocument(source, [resource({ id: 'two' })], source.id), 'command_duplicate')
    for (const command of ['', 'With Spaces', '-start', 'end-', 'two--hyphens', 'under_score', 'list', 'a'.repeat(65)]) {
        assert.equal(validateWorkspaceDocument({ ...source, embed: { ...source.embed, command } }, [], 'one'), 'command_invalid')
    }
    assert.equal(validateWorkspaceDocument({ ...source, embed: { ...source.embed, command: 'DRS-bridges-2' } }, [], 'one'), null)
    assert.equal(validateWorkspaceDocument({ ...source, embed: { ...source.embed, command: 'a'.repeat(64) } }, [], 'one'), null)
})
test('disabled embeds may omit a command; saved and published command names remain reserved', () => {
    assert.equal(validateWorkspaceDocument(resource({ embed: { enabled: false, command: '' } }), [], 'one'), null)
    assert.equal(validateWorkspaceDocument(resource(), [resource({ id: 'two', embed: { enabled: false, command: 'bridges' } })], 'one'), 'command_duplicate')
    assert.equal(validateWorkspaceDocument(resource(), [resource({ id: 'two', embed: { command: 'renamed' }, published: resource() })], 'one'), 'command_duplicate')
})
