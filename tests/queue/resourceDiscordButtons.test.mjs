import assert from 'node:assert/strict'
import test from 'node:test'
import { workspaceDocument } from '../../resources/js/utils/resourceWorkspaceData.ts'
import { resourceSavePayload } from '../../resources/js/utils/resourceSavePayload.ts'
import { cloneDocument, workspaceHasUnpublishedChanges } from '../../resources/js/utils/resourceWorkspace.ts'
import { resourceFieldValue, validateResourceFields } from '../../resources/js/utils/resourceValidation.ts'
import { resourceEmbedButtonRows } from '../../resources/js/utils/resourceEmbedPreview.ts'

function document(buttons = [{ label: 'Join Discord', url: 'https://discord.gg/example' }]) {
    return workspaceDocument({
        title: 'Guide', description: '', body: { type: 'doc', content: [{ type: 'paragraph' }] },
        access_level: 'everyone', tags: [], activity_type_ids: [], author: { name: 'Editor' },
        commands: [{ name: 'guide', enabled: true, buttons, embed: { title: 'Preparation' } }],
    }, null, new Map())
}

test('link buttons round-trip through the editor without entering the Discord embed object', () => {
    const buttons = [{ label: 'Join Discord', url: 'https://discord.gg/example' }]
    const draft = document(buttons)
    draft.embeds[0].buttons[0].label = ' Read guide '
    draft.embeds[0].buttons[0].url = ' https://example.com/guide '
    assert.equal(buttons[0].label, 'Join Discord')
    const payload = resourceSavePayload(draft, { ...draft, slug: 'guide' }, 'Updated links.')
    assert.deepEqual(payload.content.commands[0].buttons, [{ label: 'Read guide', url: 'https://example.com/guide' }])
    assert.equal('buttons' in payload.content.commands[0].embed, false)
})

test('button-only edits, removals, and order changes require publication', () => {
    const current = document([{ label: 'A', url: 'https://example.com/a' }, { label: 'B', url: 'https://example.com/b' }])
    const resource = { ...current, status: 'published', published: cloneDocument(current) }
    assert.equal(workspaceHasUnpublishedChanges(resource), false)
    for (const edit of [draft => { draft.embeds[0].buttons[0].label = 'Updated' }, draft => { draft.embeds[0].buttons[0].url += '/new' }, draft => draft.embeds[0].buttons.reverse(), draft => { draft.embeds[0].buttons = [] }]) {
        const draft = cloneDocument(resource)
        edit(draft)
        assert.equal(workspaceHasUnpublishedChanges(resource, draft), true)
        assert.equal(resource.published.embeds[0].buttons[0].label, 'A')
    }
    const legacy = { ...document([]), status: 'published', published: document([]) }
    delete legacy.published.embeds[0].buttons
    assert.equal(workspaceHasUnpublishedChanges(legacy), false)
})

test('button validation targets individual controls and observes corrections', () => {
    const draft = document([{ label: '', url: 'javascript:alert(1)' }])
    const validate = () => validateResourceFields(draft, [], 'one', key => key)
    let errors = validate()
    assert.equal(errors['commands.0.buttons.0.label'].message, 'validation.required')
    assert.equal(errors['commands.0.buttons.0.url'].message, 'validation.url')
    const invalidValue = resourceFieldValue(draft, 'commands.0.buttons.0.url')
    draft.embeds[0].buttons[0] = { label: 'Strategy', url: 'https://example.com' }
    assert.notEqual(resourceFieldValue(draft, 'commands.0.buttons.0.url'), invalidValue)
    assert.deepEqual(validate(), {})
    draft.embeds[0].buttons = Array.from({ length: 6 }, () => ({ label: 'x'.repeat(81), url: 'https://example.com/' + 'x'.repeat(494) }))
    errors = validate()
    assert.equal(errors['commands.0.buttons'].message, 'validation.max_items')
    assert.equal(errors['commands.0.buttons.0.label'].message, 'validation.max_characters')
    assert.equal(errors['commands.0.buttons.0.url'].message, 'validation.max_characters')
})

test('previews preserve order and start a second Discord row after five buttons', () => {
    const buttons = Array.from({ length: 6 }, (_, index) => ({ label: `Link ${index}`, url: `https://example.com/${index}` }))
    const rows = resourceEmbedButtonRows(buttons)
    assert.deepEqual(rows.map(row => row.length), [5, 1])
    assert.deepEqual(rows.flat(), buttons)
    assert.deepEqual(resourceEmbedButtonRows([]), [])
})
