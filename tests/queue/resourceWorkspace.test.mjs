import assert from 'node:assert/strict'
import test from 'node:test'
import { buildWorkspaceTree, cloneDocument, collectionDescendants, filterWorkspaceResources, publishWorkspaceResource, validateWorkspaceDocument, workspaceCommandErrors, workspaceCollectionPath } from '../../resources/js/utils/resourceWorkspace.ts'

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
        embeds: [{ enabled: true, command: 'bridges', fields: [{ name: 'Team', value: 'North' }] }], ...overrides }
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
    assert.deepEqual(rows.filter(item => item.kind !== 'embed').map(item => [item.kind, item.kind === 'collection' ? item.collection.id : item.resource.id, item.depth]), [
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
test('drafts include unpublished changes to live resources but exclude matching live copies', () => {
    const rows = [resource(), resource({ id: 'edited', status: 'published', hasUnpublishedChanges: true }), resource({ id: 'live', status: 'published', hasUnpublishedChanges: false })]
    assert.deepEqual(filter(rows, { scope: 'drafts' }).map(item => item.id), ['one', 'edited'])
})
test('draft snapshots are independent, without lifecycle or history fields', () => {
    const source = resource()
    const snapshot = cloneDocument(source)
    snapshot.embeds[0].fields[0].value = 'South'
    snapshot.tags.push('new')
    assert.equal(source.embeds[0].fields[0].value, 'North')
    assert.deepEqual(source.tags, ['strategy'])
    assert.equal('history' in snapshot, false)
    assert.equal('published' in snapshot, false)
})
test('publication promotes drafts and snapshots their content', () => {
    const source = resource()
    const result = publishWorkspaceResource(source)
    assert.equal(result.status, 'published')
    assert.equal(source.status, 'draft')
    result.body = 'Later edit'
    assert.equal(result.published.body, '## Plan')
    assert.strictEqual(publishWorkspaceResource(result), result)
})
test('resource title is required even without an embed', () => {
    assert.equal(validateWorkspaceDocument(resource({ title: ' ', embeds: [{ enabled: false }] }), [], 'one'), 'title_required')
})
test('commands validate their syntax and group-wide uniqueness', () => {
    const source = resource()
    assert.equal(validateWorkspaceDocument(source, [source], source.id), null)
    assert.equal(validateWorkspaceDocument(source, [resource({ id: 'two' })], source.id), 'command_duplicate')
    for (const command of ['With Spaces', '-start', 'end-', 'two--hyphens', 'under_score', 'a'.repeat(65)]) {
        assert.equal(validateWorkspaceDocument({ ...source, embeds: [{ ...source.embeds[0], command }] }, [], 'one'), 'command_invalid')
    }
    assert.equal(validateWorkspaceDocument({ ...source, embeds: [{ ...source.embeds[0], command: 'DRS-bridges-2' }] }, [], 'one'), null)
    assert.equal(validateWorkspaceDocument({ ...source, embeds: [{ ...source.embeds[0], command: 'a'.repeat(64) }] }, [], 'one'), null)
})

test('command errors distinguish missing names, reserved names, invalid syntax and duplicate names by embed index', () => {
    const source = resource({ embeds: ['', '   ', 'LiSt', 'bad name', 'west', 'WEST'].map(command => ({ command })) })
    assert.deepEqual(workspaceCommandErrors(source, [], source.id), [
        'command_required', 'command_required', 'command_reserved', 'command_invalid', null, 'command_duplicate',
    ])
    for (const command of ['', ' \t ']) {
        assert.equal(validateWorkspaceDocument(resource({ embeds: [{ command }] }), [], 'one'), 'command_required')
    }
    assert.equal(validateWorkspaceDocument(resource({ embeds: [{ command: 'LIST' }] }), [], 'one'), 'command_reserved')
})
test('empty embed lists need no command; disabled and published names remain reserved', () => {
    assert.equal(validateWorkspaceDocument(resource({ embeds: [] }), [], 'one'), null)
    assert.equal(validateWorkspaceDocument(resource({ embeds: [{ enabled: false, command: '' }] }), [], 'one'), 'command_required')
    assert.equal(validateWorkspaceDocument(resource(), [resource({ id: 'two', embeds: [{ enabled: false, command: 'bridges' }] })], 'one'), 'command_duplicate')
    assert.equal(validateWorkspaceDocument(resource(), [resource({ id: 'two', embeds: [{ command: 'renamed' }], published: resource() })], 'one'), 'command_duplicate')
})

test('embeds nest beneath their resource without inflating folder counts and collapse with their parent', () => {
    const source = resource({ embeds: [{ command: 'west', enabled: true }, { command: 'east', enabled: false }] })
    const rows = buildWorkspaceTree(collections, [source])
    const resourceIndex = rows.findIndex(item => item.kind === 'resource')
    assert.deepEqual(rows.slice(resourceIndex + 1, resourceIndex + 3).map(item => [item.kind, item.resource.id, item.index, item.embed.command, item.depth]), [
        ['embed', 'one', 0, 'west', 4], ['embed', 'one', 1, 'east', 4],
    ])
    assert.equal(rows[0].count, 1)
    assert.equal(buildWorkspaceTree(collections, [source], ['resource:one']).some(item => item.kind === 'embed'), false)
    assert.equal(buildWorkspaceTree(collections, [source], ['drs']).some(item => item.kind !== 'collection'), false)
    source.collectionId = null
    const moved = buildWorkspaceTree(collections, [source]).filter(item => item.kind !== 'collection')
    assert.deepEqual(moved.map(item => item.depth), [0, 1, 1])
})

test('every embed participates in duplicate checks and independent history snapshots', () => {
    const source = resource({ embeds: [{ command: 'west', fields: [] }, { command: 'WEST', fields: [] }] })
    assert.equal(validateWorkspaceDocument(source, [], 'one'), 'command_duplicate')
    source.embeds[1].command = 'east'
    assert.equal(validateWorkspaceDocument(source, [resource({ id: 'other', embeds: [{ command: 'east' }] })], 'one'), 'command_duplicate')
    const snapshot = cloneDocument(source)
    snapshot.embeds[1].fields.push({ name: 'Side', value: 'East' })
    assert.deepEqual(source.embeds[1].fields, [])
})
