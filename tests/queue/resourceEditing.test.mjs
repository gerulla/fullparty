import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { computed, reactive, ref, watch } from 'vue'
import * as workspaceUtils from '../../resources/js/utils/resourceWorkspace.ts'
import * as workspaceData from '../../resources/js/utils/resourceWorkspaceData.ts'
import { resourceSavePayload } from '../../resources/js/utils/resourceSavePayload.ts'
import * as validation from '../../resources/js/utils/resourceValidation.ts'

const document = (text = '') => ({ type: 'doc', content: [{ type: 'paragraph', ...(text ? { content: [{ type: 'text', text }] } : {}) }] })
const snapshot = { title: 'Untitled resource', slug: 'untitled-first', description: '', body: document(), access_level: 'everyone', tags: [], activity_type_ids: [], author: { name: 'Author' }, commands: [] }
const detail = () => ({ id: 42, collection_id: 5, slug: 'untitled-first', status: 'draft', version: 1, sort_order: 0, updated_at: '2026-09-10T10:00:00Z', working_copy: structuredClone(snapshot), published: null, history: [] })
const resource = () => workspaceData.workspaceResource(detail(), new Map())
const flush = () => new Promise(resolve => setImmediate(resolve))

test('tree embed selection preserves unsaved content and reuses the current editing lease', async () => {
    const existing = resource()
    existing.embeds = ['west', 'east'].map(name => workspaceData.workspaceEmbed({ name, enabled: true, embed: { title: name } }))
    const { api, calls } = workspaceHarness({ existing })
    api.state.resources = [existing]
    api.openEmbed(existing.id, 1); await flush()
    assert.equal(api.state.mode, 'editor')
    assert.equal(api.state.editorPane, 'embed')
    assert.equal(api.state.inspectorTab, 'discord')
    assert.equal(api.state.embedIndex, 1)
    api.state.draft.embeds[1].description = 'Unsaved east plan'
    const callCount = calls.length
    api.openEmbed(existing.id, 0); await flush()
    assert.equal(calls.length, callCount)
    assert.equal(api.state.confirmation.open, false)
    assert.equal(api.state.embedIndex, 0)
    assert.equal(api.state.draft.embeds[1].description, 'Unsaved east plan')
    api.addEmbed()
    assert.equal(api.state.editorPane, 'embed')
    assert.equal(api.state.draft.embeds.length, 3)
    assert.equal(api.state.embedIndex, 2)
    assert.equal(existing.embeds.length, 2)
    api.removeEmbed(2)
    assert.equal(api.state.draft.embeds.length, 3)
    assert.equal(api.state.confirmation.severity, 'error')
    await api.confirm()
    assert.equal(api.state.draft.embeds.length, 2)
    assert.equal(api.state.embedIndex, 1)
    api.save(); await flush()
    const payload = calls.find(call => call[0] === 'save')[2]
    assert.deepEqual(payload.content.commands.map(item => item.name), ['west', 'east'])
    assert.equal(payload.content.commands[1].embed.description, 'Unsaved east plan')
    assert.equal(api.dirty, false)
})

test('returning to the resource document preserves unsaved embeds and the editing lease', async () => {
    const { api, calls } = workspaceHarness()
    api.createResource(); await flush()
    api.state.draft.body = document('Unsaved resource text')
    api.addEmbed()
    api.state.draft.embeds[0].command = 'plan'
    api.state.draft.embeds[0].description = 'Unsaved embed text'
    const draft = api.state.draft
    const callCount = calls.length
    api.showResourceEditor()
    assert.equal(api.state.editorPane, 'resource')
    api.openEmbed(api.state.selectedId, 0)
    assert.equal(api.state.editorPane, 'embed')
    api.browseResource(api.state.selectedId)
    assert.equal(api.state.editorPane, 'resource')
    assert.equal(api.state.draft, draft)
    assert.deepEqual(draft.body, document('Unsaved resource text'))
    assert.equal(draft.embeds[0].description, 'Unsaved embed text')
    assert.equal(calls.length, callCount)
    assert.equal(api.state.confirmation.open, false)
})

test('removing an earlier embed keeps the selected embed and removing the last returns to the document', async () => {
    const { api } = workspaceHarness()
    api.createResource(); await flush()
    for (const name of ['west', 'east', 'north']) { api.addEmbed(); api.state.draft.embeds.at(-1).command = name }
    const selected = api.state.draft.embeds[2]
    api.removeEmbed(0); await api.confirm()
    assert.equal(api.state.embedIndex, 1)
    assert.equal(api.state.draft.embeds[api.state.embedIndex], selected)
    assert.equal(api.state.editorPane, 'embed')
    api.removeEmbed(0); await api.confirm()
    api.removeEmbed(0); await api.confirm()
    assert.equal(api.state.draft.embeds.length, 0)
    assert.equal(api.state.editorPane, 'resource')
})

test('embed creation stops at fifteen and saving an oversized document makes no request', async () => {
    const { api, calls } = workspaceHarness()
    api.createResource(); await flush()
    for (let index = 0; index < 15; index++) { api.addEmbed(); api.state.draft.embeds.at(-1).command = `plan-${index}` }
    api.addEmbed()
    assert.equal(api.state.draft.embeds.length, 15)
    assert.equal(api.state.embedIndex, 14)
    api.state.draft.embeds.push(workspaceData.workspaceEmbed({ name: 'extra', embed: {} }))
    api.save(); await flush()
    assert.equal(calls.some(call => call[0] === 'save'), false)
    assert.equal(api.fieldError('commands'), 'groups.resources.workspace.embed_limit')
    api.state.draft.embeds.pop()
    api.save(); await flush()
    assert.equal(calls.find(call => call[0] === 'save')[2].content.commands.length, 15)
})

test('every present embed is enabled in the save payload without an editor toggle', () => {
    const existing = resource()
    existing.embeds = [workspaceData.workspaceEmbed({ name: 'plan', enabled: false, embed: { title: 'Plan' } })]
    const payload = resourceSavePayload(workspaceUtils.cloneDocument(existing), existing, '', false)
    assert.equal(payload.content.commands[0].enabled, true)
    assert.equal(existing.embeds[0].enabled, false)
})

test('saving an empty command opens its embed with a required field error that clears as it is corrected', async () => {
    const { api, calls } = workspaceHarness()
    api.createResource(); await flush()
    api.addEmbed(); api.state.draft.embeds[0].command = 'west'
    api.addEmbed()
    api.showResourceEditor()
    const draft = api.state.draft
    assert.equal(api.commandError(1), undefined)
    api.save(true); await flush()
    assert.equal(calls.some(call => call[0] === 'save'), false)
    assert.equal(api.state.embedIndex, 1)
    assert.equal(api.state.editorPane, 'embed')
    assert.equal(api.state.inspectorTab, 'discord')
    assert.equal(api.state.error, 'groups.resources.workspace.validation.fix_fields')
    assert.equal(api.commandError(0), undefined)
    assert.equal(api.commandError(1), 'groups.resources.workspace.command_required')
    for (const [value, key] of [['LIST', 'command_reserved'], ['east bridge', 'command_invalid'], ['WEST', 'command_duplicate']]) {
        draft.embeds[1].command = value
        assert.equal(api.commandError(1), `groups.resources.workspace.${key}`)
    }
    draft.embeds[1].command = 'east'
    assert.equal(api.commandError(1), undefined)
    assert.equal(api.state.draft, draft)
    api.save(true); await flush()
    assert.deepEqual(calls.find(call => call[0] === 'save')[2].content.commands.map(item => item.name), ['west', 'east'])
})

test('confirming a save revalidates commands before sending the resource', async () => {
    const existing = resource(); existing.history = [{ id: '1', summary: 'Created' }]
    const { api, calls } = workspaceHarness({ existing })
    api.createResource(); await flush()
    api.addEmbed(); api.state.draft.embeds[0].command = 'plan'
    api.save()
    assert.equal(api.state.saveDialog, true)
    api.state.summary = 'Changed the plan'
    api.state.draft.embeds[0].command = ''
    await api.confirmSave()
    assert.equal(api.state.saveDialog, false)
    assert.equal(api.commandError(0), 'groups.resources.workspace.command_required')
    assert.equal(calls.some(call => call[0] === 'save'), false)
})

for (const path of ['commands.1.name', 'content.commands.1.name']) {
    test(`server validation for ${path} highlights the correct command and clears after renaming`, async () => {
        const { api, mutations } = workspaceHarness()
        api.createResource(); await flush()
        for (const command of ['west', 'east']) { api.addEmbed(); api.state.draft.embeds.at(-1).command = command }
        api.showResourceEditor()
        mutations.mutate = async () => { throw { isAxiosError: true, response: { status: 422, data: { errors: { [path]: ['This command name was just taken.'] } } } } }
        api.save(); await flush()
        assert.equal(api.state.editorPane, 'embed')
        assert.equal(api.state.embedIndex, 1)
        assert.equal(api.commandError(0), undefined)
        assert.equal(api.commandError(1), 'This command name was just taken.')
        assert.equal(api.state.error, 'This command name was just taken.')
        api.state.draft.embeds[1].command = 'east-new'
        assert.equal(api.commandError(1), undefined)
        api.removeEmbed(0); await api.confirm()
        assert.deepEqual(api.state.commandErrors, {})
    })
}

test('embed previews use current resource attribution and omit links for private or restricted resources', async () => {
    const library = { visibility: 'public' }
    const embedContext = { group_icon_url: 'https://fullparty.gg/storage/group.png', public_base_url: 'https://resources.fullparty.gg/group' }
    const { api } = workspaceHarness({ library, embedContext })
    api.createResource(); await flush()
    api.addEmbed()
    const embed = api.state.draft.embeds[0]
    embed.author = 'Forged author'; embed.authorUrl = 'https://example.com'; embed.authorIcon = 'https://example.com/icon.png'
    embed.timestamp = '2026-09-11T12:00:00Z'
    api.state.draft.title = 'New resource name'
    const preview = api.embedPreview(api.state.draft, embed)
    assert.equal(preview.author, 'New resource name')
    assert.equal(preview.authorIcon, embedContext.group_icon_url)
    assert.equal(preview.authorUrl, 'https://resources.fullparty.gg/group/untitled-first')
    assert.equal(preview.timestamp, embed.timestamp)
    assert.equal(embed.author, 'Forged author', 'preview derivation does not mutate the editable document')
    for (const access of ['moderators', 'admins']) {
        api.state.draft.access = access
        assert.equal(api.embedPreview(api.state.draft, embed).authorUrl, '')
    }
    api.state.draft.access = 'everyone'; library.visibility = 'private'
    assert.equal(api.embedPreview(api.state.draft, embed).authorUrl, '')
    embedContext.group_icon_url = null
    assert.equal(api.embedPreview(api.state.draft, embed).authorIcon, '')
})

test('View selects public or group reader links from live metadata and reacts to library visibility changes', async () => {
    const existing = resource()
    existing.readerUrls = { public: 'https://resources.fullparty.test/group/live-slug', group: 'https://fullparty.test/en/groups/group/dashboard/resources/live-slug' }
    existing.status = 'published'
    const library = { visibility: 'public' }
    const { api, calls } = workspaceHarness({ existing, library })
    api.createResource(); await flush()
    const count = calls.length
    api.state.draft.access = 'admins'; api.state.draft.title = 'Unsaved title'
    assert.equal(api.viewUrl(), existing.readerUrls.public)
    assert.equal(calls.length, count, 'view URLs do not fetch data, save, or interrupt editing')
    library.visibility = 'private'
    assert.equal(api.viewUrl(), existing.readerUrls.group)
    library.visibility = 'public'
    assert.equal(api.viewUrl({ ...existing, readerUrls: { ...existing.readerUrls, public: null } }), existing.readerUrls.group)
    assert.equal(api.viewUrl({ ...existing, readerUrls: null }), undefined)
    assert.equal(api.state.mode, 'editor')
    assert.equal(api.state.draft.title, 'Unsaved title')
})

test('unpublished resource embeds can be edited immediately', async () => {
    const existing = resource()
    existing.hasUnpublishedChanges = true
    existing.embeds = ['west', 'east'].map(name => workspaceData.workspaceEmbed({ name, enabled: true, embed: { title: name } }))
    const { api, calls } = workspaceHarness({ existing })
    api.state.resources = [existing]
    api.openEmbed(existing.id, 1); await flush()
    assert.equal(api.state.mode, 'editor')
    assert.equal(api.state.selectedId, existing.id)
    assert.equal(api.state.embedIndex, 1)
    assert.equal(calls.some(call => call[0] === 'acquire'), true)
})

function loadModule(path, modules, globals = {}) {
    const source = readFileSync(new URL(path, import.meta.url), 'utf8')
    const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {}
    new Function('require', 'exports', ...Object.keys(globals), compiled)(name => {
        assert.ok(name in modules, `Unexpected dependency: ${name}`)
        return modules[name]
    }, exports, ...Object.values(globals))
    return exports
}

function workspaceHarness({ existing = resource(), fail = () => false, revisions = {}, library, embedContext, recovery, fields = [], collections = [{ id: 5, parent_id: null, name: 'Guides' }] } = {}) {
    const calls = []
    let autosaveTimer
    let saved = structuredClone(existing)
    const mutations = {
        busy: ref(false),
        acquire: async id => { calls.push(['acquire', id]); if (fail('acquire')) throw new Error('Locked'); return structuredClone(saved) },
        release: async () => { calls.push(['release']) },
        upload: async () => '/resource-assets/image',
        revision: async (id, revisionId) => {
            calls.push(['revision', id, revisionId])
            if (fail('revision')) throw new Error('Revision unavailable')
            return structuredClone(revisions[revisionId])
        },
        remove: async id => { calls.push(['delete', id]); if (fail('delete')) throw new Error('Delete failed') },
        mutate: async (id, version, action, payload) => {
            calls.push([action, id, payload])
            if (fail(action)) throw new Error('Request failed')
            if (action === 'save' || action === 'autosave') {
                saved = { ...saved, ...workspaceData.workspaceDocument({ ...payload.content, author: { name: 'Author' } }, payload.collection_id, new Map()), version: version + 1 }
                if (action === 'save') saved.history.unshift({ id: String(saved.history.length + 1), author: 'Author', summary: payload.summary ?? `Author created ${saved.title}`, at: saved.updatedAt })
            }
            if (action === 'publish' || payload?.publish) { saved.status = 'published'; saved.published = workspaceUtils.cloneDocument(saved) }
            saved.hasUnpublishedChanges = workspaceUtils.workspaceHasUnpublishedChanges(saved, saved)
            return structuredClone(saved)
        },
    }
    const creation = {
        creatingResource: ref(false), creatingCollection: ref(false), load: async () => structuredClone(saved),
        create: async collectionId => { calls.push(['create']); saved.collectionId = collectionId; return structuredClone(saved) },
        createCollection: async (name, parentId) => { calls.push(['createCollection', name, parentId]); return { id: '100', name, parentId, icon: 'i-lucide-folder', order: 0 } },
    }
    const collectionModule = loadModule('../../resources/js/composables/useResourceCollections.ts', {
        axios: { default: { isAxiosError: () => false } }, vue: { reactive },
        'ziggy-js': { route: name => name }, '@/utils/resourceWorkspaceData': workspaceData,
    })
    const api = loadModule('../../resources/js/composables/useResourceWorkspace.ts', {
        axios: { default: { isAxiosError: error => error?.isAxiosError === true } },
        vue: { computed, reactive, ref, nextTick: callback => Promise.resolve().then(callback), provide: () => {}, onMounted: () => {}, onBeforeUnmount: () => {} },
        'vue-i18n': { useI18n: () => ({ t: key => key, locale: ref('en') }) },
        '@/utils/resourceWorkspace': workspaceUtils,
        '@/utils/resourceWorkspaceData': workspaceData,
        './useResourceCreation': { useResourceCreation: () => creation },
        './useResourceMutations': { useResourceMutations: () => mutations },
        './useResourceCollections': collectionModule,
        './useResourceImageUpload': { resourceImageUploadKey: Symbol('upload') },
        '@/utils/resourceSavePayload': { resourceSavePayload },
        '@/utils/resourceValidation': validation,
        './useResourceNavigation': { useResourceNavigation: () => {} },
        './useResourceAutosave': loadModule('../../resources/js/composables/useResourceAutosave.ts', { vue: { ref, watch, onBeforeUnmount: () => {} } }, {
            setTimeout: callback => { autosaveTimer = callback; return 1 }, clearTimeout: () => { autosaveTimer = undefined },
        }),
    }, { document: { querySelectorAll: () => fields }, sessionStorage: { getItem: key => recovery?.get(key) ?? null, setItem: (key, value) => recovery?.set(key, value), removeItem: key => recovery?.delete(key) } }).useResourceWorkspace({ groupSlug: 'group', collections, library, data: { editor_user_id: recovery ? 1 : undefined, resources: [], authors: [], activities: [], access_levels: ['everyone'], embed_context: embedContext } })
    return { api, calls, mutations, async runAutosave() { const timer = autosaveTimer; autosaveTimer = undefined; timer?.(); await flush() } }
}

for (const kind of ['collection', 'resource']) {
    test(`${kind} moves render immediately, reconcile on success, and roll back positions on failure`, async () => {
        const collections = [
            { id: 5, parent_id: null, name: 'A', sort_order: 5 },
            { id: 6, parent_id: null, name: 'B', sort_order: 9 },
            { id: 7, parent_id: 5, name: 'Child', sort_order: 3 },
        ]
        const { api, mutations } = workspaceHarness({ collections })
        api.state.resources = [resource(), { ...resource(), id: '43', collectionId: null, order: 7 }, { ...resource(), id: '44', collectionId: null, order: 7 }]
        const id = kind === 'collection' ? '7' : '42'
        const beforeId = kind === 'collection' ? '6' : '44'
        const positions = () => kind === 'collection'
            ? api.state.collections.map(item => [item.id, item.parentId, item.order])
            : api.state.resources.map(item => [item.id, item.collectionId, item.order])
        const previous = positions()
        let resolve, reject, requests = 0
        mutations.organize = () => { requests++; return new Promise((yes, no) => { resolve = yes; reject = no }) }
        api.organize(kind, id, null, beforeId)
        const moved = positions().find(item => item[0] === id)
        assert.deepEqual(moved, [id, null, 1])
        assert.equal(api.busy, true)
        api.organize(kind, id, '5')
        assert.equal(requests, 1, 'in-flight moves cannot race with another mutation')
        api.state.resources[0].version = 12
        api.state.resources[0].body = document('Keep this content')
        reject(new Error('Move failed')); await flush()
        assert.deepEqual(positions(), previous)
        assert.equal(api.state.resources[0].version, 12)
        assert.deepEqual(api.state.resources[0].body, document('Keep this content'))
        assert.equal(api.busy, false)
        assert.ok(api.state.error)

        api.organize(kind, id, null, beforeId)
        assert.deepEqual(positions().find(item => item[0] === id), [id, null, 1])
        resolve({
            collections: api.state.collections.map(item => ({ id: Number(item.id), parent_id: item.parentId === null ? null : Number(item.parentId), name: item.name, sort_order: item.order + 10 })),
            resources: api.state.resources.map(item => ({ id: Number(item.id), collection_id: item.collectionId === null ? null : Number(item.collectionId), sort_order: item.order + 10, version: item.version + 1 })),
        })
        await flush()
        assert.deepEqual(positions().find(item => item[0] === id), [id, null, 11])
        assert.equal(api.state.resources[0].version, 13)
        assert.deepEqual(api.state.resources[0].body, document('Keep this content'))
        assert.equal(api.state.error, '')
        assert.equal(api.busy, false)
    })
}

test('optimistic editor moves happen before lease release and preserve a released version on rollback', async () => {
    const { api, mutations } = workspaceHarness()
    api.createResource('5'); await flush()
    let release, reject
    mutations.release = () => new Promise(resolve => { release = resolve })
    mutations.organize = () => new Promise((resolve, fail) => { reject = fail })
    api.organize('resource', '42', null)
    assert.equal(api.selected.collectionId, null)
    release({ id: 42, version: 20 }); await flush()
    reject(new Error('Move failed')); await flush()
    assert.equal(api.selected.collectionId, '5')
    assert.equal(api.selected.version, 20)
    assert.equal(api.busy, false)
})

test('creating the first resource immediately opens a root draft without requiring a folder', async () => {
    const { api, calls } = workspaceHarness({ collections: [] })
    api.createResource(); await flush()
    assert.equal(api.collectionActions.state.editing, null)
    assert.equal(calls.some(call => call[0] === 'create'), true)
    assert.equal(api.state.mode, 'editor')
    assert.equal(api.state.draft.collectionId, null)
    assert.equal(api.state.collections.length, 0)
})

test('cancelling inline naming does not create a resource on the next ordinary folder creation', async () => {
    const { api, calls } = workspaceHarness({ collections: [] })
    api.collectionActions.create(null)
    api.collectionActions.cancel()
    api.collectionActions.create(null)
    api.collectionActions.state.editing.name = 'Just a folder'
    await api.collectionActions.save()
    assert.equal(calls.some(call => call[0] === 'create'), false)
    assert.equal(api.state.mode, 'library')
})

test('folder context creation uses that folder and All resources creates at root', async () => {
    const { api } = workspaceHarness()
    api.createResource('5'); await flush()
    assert.equal(api.state.draft.collectionId, '5')
    api.browse('all'); await flush()
    api.createResource(); await flush()
    assert.equal(api.state.draft.collectionId, null)
})

test('creating a collection during resource editing keeps unsaved content and its editor lease', async () => {
    const { api, calls } = workspaceHarness()
    api.createResource(); await flush()
    api.state.draft.title = 'Unsaved resource'
    const releases = calls.filter(call => call[0] === 'release').length
    api.collectionActions.create('5')
    api.collectionActions.state.editing.name = 'Child'
    await api.collectionActions.save()
    assert.equal(api.state.mode, 'editor')
    assert.equal(api.state.draft.title, 'Unsaved resource')
    assert.equal(api.dirty, true)
    assert.equal(calls.filter(call => call[0] === 'release').length, releases)
    assert.equal(api.state.collections.find(item => item.id === '100').parentId, '5')
})

test('Uploads navigation releases the editor lease and returns to the resource library', async () => {
    const { api, calls } = workspaceHarness()
    api.createResource(); await flush()
    api.browse('uploads'); await flush()
    assert.equal(api.state.mode, 'uploads')
    assert.equal(api.state.scope, 'uploads')
    assert.equal(api.state.draft, null)
    assert.equal(api.state.selectedId, null)
    assert.ok(calls.some(call => call[0] === 'release'))
    api.browse('all'); await flush()
    assert.equal(api.state.mode, 'library')
})

test('opening Uploads autosaves changes before releasing the editor', async () => {
    const { api, calls } = workspaceHarness()
    api.createResource(); await flush()
    api.state.draft.title = 'Unsaved guide'
    api.browse('uploads'); await flush()
    assert.equal(api.state.mode, 'uploads')
    assert.equal(calls.find(call => call[0] === 'autosave')[2].content.title, 'Unsaved guide')
})

test('initial creation acquires a lease and first save needs no summary modal', async () => {
    const { api, calls } = workspaceHarness()
    api.createResource(); api.createResource()
    await flush()
    assert.equal(calls.filter(call => call[0] === 'create').length, 1)
    assert.equal(api.state.mode, 'editor')
    api.state.draft.title = 'DRS Bridges'
    api.save()
    await flush()
    assert.equal(api.state.saveDialog, false)
    assert.equal(calls.find(call => call[0] === 'save')[2].summary, undefined)
    assert.equal(api.selected.history[0].summary, 'Author created DRS Bridges')
    assert.equal(api.dirty, false)
})

test('edited resources ask for a sentence and do not save until confirmed', async () => {
    const existing = resource(); existing.history = [{ id: '1', summary: 'Created', author: 'Author', at: existing.updatedAt }]
    const { api, calls } = workspaceHarness({ existing })
    api.createResource(); await flush()
    api.state.draft.body = document('Updated')
    api.save(); await flush()
    assert.equal(api.state.saveDialog, true)
    assert.equal(calls.filter(call => call[0] === 'save').length, 0)
    await api.confirmSave()
    assert.equal(calls.filter(call => call[0] === 'save').length, 0)
    api.state.summary = 'Clarified the bridge assignments.'
    await api.confirmSave()
    assert.deepEqual(api.selected.body, document('Updated'))
    assert.equal(api.selected.history[0].summary, 'Clarified the bridge assignments.')
    assert.equal(api.state.saveDialog, false)
    assert.equal(api.dirty, false)
})

test('Publish follows differences from live, including unsaved changes and reverting them', async () => {
    const existing = resource()
    existing.status = 'published'; existing.published = workspaceUtils.cloneDocument(existing)
    existing.hasUnpublishedChanges = false; existing.history = [{ id: '1', summary: 'Created' }]
    const { api, calls } = workspaceHarness({ existing })
    api.createResource(); await flush()
    assert.equal(api.canPublish, false)
    api.state.draft.title = 'New title'
    assert.equal(api.canPublish, true)
    api.state.draft.title = existing.title
    assert.equal(api.canPublish, false)
    api.state.draft.body = document('Unpublished edit')
    api.save(true); await flush()
    assert.equal(api.state.saveDialog, true)
    assert.equal(api.state.publishAfterSave, true)
    assert.equal(calls.some(call => call[0] === 'save'), false)
    api.state.summary = 'Clarified the plan'
    await api.confirmSave()
    assert.equal(calls.find(call => call[0] === 'save')[2].publish, true)
    assert.equal(api.canPublish, false)
    assert.equal(api.dirty, false)
    assert.deepEqual(api.selected.published.body, document('Unpublished edit'))
    assert.equal(api.state.mode, 'editor')
})

test('saved draft content stays private until publication with a changelog sentence', async () => {
    const existing = resource()
    existing.status = 'published'; existing.published = workspaceUtils.cloneDocument(existing)
    existing.hasUnpublishedChanges = false; existing.history = [{ id: '1', summary: 'Created' }]
    const { api, calls } = workspaceHarness({ existing })
    api.createResource(); await flush()
    api.state.draft.body = document('Saved draft')
    api.save(); api.state.summary = 'Prepared the new plan'; await api.confirmSave()
    assert.equal(api.canPublish, true)
    assert.equal(api.dirty, false)
    assert.deepEqual(api.selected.published.body, existing.body)
    api.save(true); await flush()
    assert.equal(api.state.saveDialog, true)
    api.state.summary = 'Published the new plan'
    await api.confirmSave()
    assert.equal(calls.filter(call => call[0] === 'save').length, 2)
    assert.equal(calls.filter(call => call[0] === 'save').at(-1)[2].publish, true)
    assert.equal(api.canPublish, false)
    assert.equal(api.state.mode, 'editor')
})

test('automatic embed attribution and timestamps do not trigger Publish', () => {
    const existing = resource()
    existing.embeds = [workspaceData.workspaceEmbed({ name: 'plan', embed: { title: 'Plan' } })]
    existing.status = 'published'; existing.published = workspaceUtils.cloneDocument(existing)
    const draft = workspaceUtils.cloneDocument(existing)
    Object.assign(draft.embeds[0], { author: 'Refreshed', authorIcon: '/new-icon.png', authorUrl: 'https://example.com', timestamp: '2026-09-11T10:00:00Z' })
    draft.collectionId = null
    assert.equal(workspaceUtils.workspaceHasUnpublishedChanges(existing, draft), false)
    draft.embeds[0].description = 'New instructions'
    assert.equal(workspaceUtils.workspaceHasUnpublishedChanges(existing, draft), true)
})

test('failed saves preserve the editor content and sentence for retry', async () => {
    let failSave = true
    const existing = resource(); existing.history = [{ id: '1', summary: 'Created' }]
    const { api } = workspaceHarness({ existing, fail: action => action === 'save' && failSave })
    api.createResource(); await flush()
    api.state.draft.body = document('Do not lose this')
    api.save(); api.state.summary = 'Updated the instructions.'
    await api.confirmSave()
    assert.deepEqual(api.state.draft.body, document('Do not lose this'))
    assert.equal(api.state.summary, 'Updated the instructions.')
    assert.equal(api.state.saveDialog, true)
    assert.equal(api.dirty, true)
    failSave = false
    await api.confirmSave()
    assert.equal(api.dirty, false)
})

test('loading a history version fills every editor field without writing or changing published content', async () => {
    const existing = resource()
    existing.history = [{ id: '2', summary: 'Latest' }, { id: '1', summary: 'Original' }]
    existing.published = workspaceUtils.cloneDocument(existing)
    const old = { ...snapshot, title: 'Original plan', description: 'Original description', body: document('Original body'), collection_id: 7, access_level: 'moderator', tags: ['original'], activity_type_ids: [3], author: { id: 90, name: 'Original author', avatar_url: '/author.png' }, metadata_image_id: 'a1234567-1234-1234-1234-123456789012', commands: [{ name: 'old-plan', enabled: true, embed: { title: 'Plan', description: 'Instructions', color: 0x123456, url: 'https://example.com/guide', author: { name: 'Author', url: 'https://example.com/author', icon_url: 'https://example.com/avatar.png' }, thumbnail: { url: 'https://example.com/thumb.png' }, image: { asset_id: 'a1234567-1234-1234-1234-123456789012' }, timestamp: '2026-09-01T10:00:00Z', fields: [{ name: 'West', value: 'Party A', inline: true }] } }] }
    const revisions = { 1: { id: 1, snapshot: old } }
    const { api, calls } = workspaceHarness({ existing, revisions })
    api.createResource(); await flush()
    api.state.collections.push({ id: '7', name: 'Original folder', parentId: null, icon: 'i-lucide-folder' })
    api.useRevision('1'); await flush()
    assert.deepEqual(api.state.draft, workspaceData.workspaceDocument(old, 7, new Map()))
    assert.equal(api.state.sourceRevisionId, '1')
    assert.equal(api.dirty, true)
    assert.deepEqual(api.selected.history, existing.history)
    assert.deepEqual(api.selected.published, existing.published)
    assert.equal(calls.some(call => ['save', 'restore', 'submit', 'publish'].includes(call[0])), false)
    api.state.draft.body = document('Original body with new edits')
    assert.deepEqual(revisions[1].snapshot.body, document('Original body'))
    api.save(); await flush()
    assert.equal(api.state.saveDialog, true)
    api.state.summary = 'Started from the original plan.'
    await api.confirmSave()
    const payload = calls.find(call => call[0] === 'save')[2]
    assert.equal(payload.source_revision_id, 1)
    assert.equal('character_id' in payload.content, false)
    assert.deepEqual(payload.content.body, document('Original body with new edits'))
    assert.equal(payload.collection_id, 7)
    assert.equal(api.selected.history.length, 3)
    assert.deepEqual(api.selected.history.slice(1), existing.history)
    assert.deepEqual(api.selected.published, existing.published)
    assert.equal(api.state.sourceRevisionId, null)
    assert.equal(api.dirty, false)
})

test('switching versions autosaves current text and leaves it intact on a failed fetch', async () => {
    const existing = resource(); existing.history = [{ id: '1', summary: 'Original' }]
    let fail = true
    const { api, calls } = workspaceHarness({ existing, revisions: { 1: { id: 1, snapshot } }, fail: action => action === 'revision' && fail })
    api.createResource(); await flush()
    api.state.draft.title = 'Unsaved edits'
    api.useRevision('1'); await flush()
    assert.equal(api.state.draft.title, 'Unsaved edits')
    assert.ok(api.state.error)
    assert.equal(api.state.sourceRevisionId, null)
    fail = false
    api.useRevision('1'); await flush()
    assert.equal(api.state.confirmation.open, false)
    assert.equal(calls.find(call => call[0] === 'autosave')[2].content.title, 'Unsaved edits')
    assert.equal(api.state.draft.title, snapshot.title)
    assert.equal(api.state.sourceRevisionId, '1')
})

test('versions with missing or deleted folders keep the current folder and still create a new save', async () => {
    for (const collection_id of [undefined, 999]) {
        const existing = resource(); existing.history = [{ id: '1', summary: 'Original' }]
        const { api, calls } = workspaceHarness({ existing, revisions: { 1: { id: 1, snapshot: { ...snapshot, collection_id } } } })
        api.createResource('5'); await flush()
        api.useRevision('1'); await flush()
        assert.equal(api.state.draft.collectionId, '5')
        assert.equal(api.state.sourceRevisionId, '1')
        api.save(); api.state.summary = 'Reuse this version.'; await api.confirmSave()
        assert.equal(calls.filter(call => call[0] === 'save').length, 1)
        assert.equal(api.selected.history.length, 2)
        assert.equal(api.state.sourceRevisionId, null)
    }
})

test('a revision source preserves its author unless a different author is selected', () => {
    const original = resource()
    const source = { id: '10', document: { ...workspaceUtils.cloneDocument(original), author: 'Past author', authorCharacterId: 90 } }
    const draft = workspaceUtils.cloneDocument(source.document)
    const payload = resourceSavePayload(draft, original, 'Reused.', false, source)
    assert.equal(payload.source_revision_id, 10)
    assert.equal('character_id' in payload.content, false)
    draft.author = 'New author'; draft.authorCharacterId = 15
    assert.equal(resourceSavePayload(draft, original, 'Reattributed.', false, source).content.character_id, 15)
})

test('publish saves atomically and retains the editor, then confirmed deletion updates the library', async () => {
    const { api, calls } = workspaceHarness()
    api.createResource(); await flush()
    api.state.draft.title = 'Ready guide'
    api.save(true); await flush()
    assert.equal(calls.find(call => call[0] === 'save')[2].publish, true)
    assert.equal(api.state.mode, 'editor')
    assert.equal(api.canPublish, false)
    assert.equal(api.selected.status, 'published')
    api.remove('42')
    assert.equal(api.state.confirmation.open, true)
    assert.equal(calls.filter(call => call[0] === 'delete').length, 0)
    await api.confirm()
    assert.equal(api.state.resources.length, 0)
    assert.equal(api.state.selectedId, null)
})

test('failed deletion leaves the resource and confirmation intact', async () => {
    const { api } = workspaceHarness({ fail: action => action === 'delete' })
    api.createResource(); await flush()
    api.remove('42')
    assert.equal(await api.confirm(), false)
    assert.equal(api.state.confirmation.open, true)
    assert.equal(api.state.resources.length, 1)
})

test('leaving the editor retains the released version for later library actions', async () => {
    const { api, mutations } = workspaceHarness()
    api.createResource(); await flush()
    mutations.release = async () => ({ id: 42, version: 12 })
    api.back(); await flush()
    assert.equal(api.state.mode, 'library')
    assert.equal(api.selected.version, 12)
})

test('payload preserves resource attribution and editable embed fields but omits forced metadata', () => {
    const original = resource()
    const draft = workspaceUtils.cloneDocument(original)
    const uuid = 'a1234567-1234-1234-1234-123456789012'
    draft.activityTypeIds = [3, 8]; draft.activities = ['Same label', 'Same label']
    draft.cover = `/resource-assets/${uuid}`
    draft.embeds = [{ enabled: true, command: 'bridges', title: 'Bridge plan', description: 'Both groups', color: '#12abcd', url: 'https://example.com', author: 'Author', authorUrl: 'https://example.com/author', authorIcon: 'https://example.com/avatar.png', image: draft.cover, thumbnail: 'https://example.com/thumb.png', timestamp: '2026-09-10T10:00:00Z', fields: [{ name: 'West', value: 'Party A', inline: true }] }]
    const { content } = resourceSavePayload(draft, original, 'Updated.', true)
    assert.equal(content.slug, original.slug)
    assert.equal('character_id' in content, false)
    assert.deepEqual(content.activity_type_ids, [3, 8])
    assert.equal(content.metadata_image_id, uuid)
    assert.deepEqual(content.commands[0].embed.image, { asset_id: uuid })
    assert.deepEqual(content.commands[0].embed.thumbnail, { url: 'https://example.com/thumb.png' })
    assert.equal(content.commands[0].embed.color, 0x12abcd)
    assert.equal('author' in content.commands[0].embed, false)
    assert.equal('timestamp' in content.commands[0].embed, false)
    assert.equal('updated_at' in content.commands[0], false)
    draft.author = 'Character'; draft.authorCharacterId = 20
    assert.equal(resourceSavePayload(draft, original, '', false).content.character_id, 20)
})

test('lease requests serialize versions and remain active through saving and publication', async () => {
    const calls = []
    let version = 1
    let heartbeat
    const api = loadModule('../../resources/js/composables/useResourceMutations.ts', {
        axios: { default: { post: async (action, payload) => {
            calls.push([action, payload]); assert.equal(payload.version, version); version++
            return { data: { data: { version, editing_token: 'token', resource: { ...detail(), version } } } }
        } } },
        vue: { ref }, 'ziggy-js': { route: (_, params) => params.operation }, '@/utils/resourceWorkspaceData': workspaceData,
    }, { setInterval: callback => { heartbeat = callback; return 1 }, clearInterval: () => {} }).useResourceMutations(() => 'group', () => new Map(), assert.fail)
    await api.acquire('42', 1)
    heartbeat()
    const save = api.mutate('42', 1, 'save', { content: {}, publish: true })
    const publish = api.mutate('42', 1, 'publish')
    const release = api.release()
    await Promise.all([save, publish, release])
    assert.deepEqual(calls.map(call => call[0]), ['acquire', 'heartbeat', 'save', 'publish', 'release'])
    assert.equal(calls[2][1].editing_token, 'token')
    assert.equal(calls[3][1].version, 4)
    assert.equal(calls[3][1].editing_token, 'token')
    assert.equal(calls[4][1].editing_token, 'token')
})

test('a temporary heartbeat failure keeps the lease available for the next save', async () => {
    const errors = []
    const calls = []
    let heartbeat
    const api = loadModule('../../resources/js/composables/useResourceMutations.ts', {
        axios: { default: { isAxiosError: () => false, post: async (action, payload) => {
            calls.push([action, payload])
            if (action === 'heartbeat') throw new Error('Offline')
            return { data: { data: { version: payload.version + 1, editing_token: 'token', resource: detail() } } }
        } } },
        vue: { ref }, 'ziggy-js': { route: (_, params) => params.operation }, '@/utils/resourceWorkspaceData': workspaceData,
    }, { setInterval: callback => { heartbeat = callback; return 1 }, clearInterval: () => {} }).useResourceMutations(() => 'group', () => new Map(), error => errors.push(error))
    await api.acquire('42', 1)
    heartbeat(); await flush()
    await api.mutate('42', 1, 'save', { content: {} })
    assert.equal(errors.length, 1)
    assert.equal(calls[2][1].editing_token, 'token')
    assert.equal(calls[2][1].version, 2)
    await api.release()
})

test('autosave responses never replace text typed while the request was in flight', async () => {
    const { api, mutations } = workspaceHarness()
    api.createResource(); await flush()
    api.state.draft.title = 'First edit'
    let complete
    mutations.mutate = async (_id, _version, action, payload) => {
        assert.equal(action, 'autosave')
        const submitted = { ...JSON.parse(JSON.stringify(api.selected)), title: payload.content.title, version: 2 }
        await new Promise(resolve => { complete = resolve })
        return submitted
    }
    const saving = api.retrySave()
    api.state.draft.title = 'Newer typing'
    complete(); await flush()
    assert.equal(api.state.draft.title, 'Newer typing')
    assert.equal(api.selected.title, 'First edit')
    complete(); await saving
    assert.equal(api.dirty, false)
    assert.equal(api.selected.title, 'Newer typing')
})

test('failed or invalid autosaves block leaving the editor and preserve every field', async () => {
    const { api } = workspaceHarness({ fail: action => action === 'autosave' })
    api.createResource(); await flush()
    api.state.draft.title = 'Offline work'
    api.browse('uploads'); await flush()
    assert.equal(api.state.mode, 'editor')
    assert.equal(api.state.draft.title, 'Offline work')
    assert.equal(api.state.autosaveError, false)
    assert.equal(api.state.error, '')
    api.state.draft.title = ''
    await api.retrySave()
    assert.ok(api.fieldError('title'))
    api.state.draft.title = 'Fixed'
    assert.equal(api.fieldError('title'), undefined)
})

test('incomplete titles and embeds skip background autosave without showing or focusing errors', async () => {
    let focused = 0
    const recovery = new Map()
    const fields = [{ dataset: { resourceField: 'title' }, querySelector: () => ({ focus: () => focused++ }), scrollIntoView() {} }]
    const { api, calls, runAutosave } = workspaceHarness({ fields, recovery })
    api.createResource(); await flush()
    api.state.draft.title = ''
    await runAutosave()
    assert.equal(calls.some(call => call[0] === 'autosave'), false)
    assert.equal(api.dirty, true)
    assert.equal(api.state.error, '')
    assert.equal(api.state.autosaveError, false)
    assert.deepEqual(api.state.fieldErrors, {})
    assert.equal(api.state.commandValidationAttempted, false)
    assert.equal(focused, 0)
    assert.equal(recovery.size, 1)

    api.state.draft.title = 'A finished title'
    api.addEmbed()
    await runAutosave()
    assert.equal(calls.some(call => call[0] === 'autosave'), false)
    assert.equal(api.commandError(0), undefined)
    assert.equal(api.state.error, '')

    api.state.draft.embeds[0].command = 'guide'
    await runAutosave()
    assert.equal(calls.filter(call => call[0] === 'autosave').length, 1)
    assert.equal(api.dirty, false)
    assert.equal(recovery.size, 0)
    assert.equal(focused, 0)
})

for (const status of [422, 409, 500]) {
    test(`background HTTP ${status} failures stay quiet until a manual save reports them`, async () => {
        const { api, mutations, runAutosave } = workspaceHarness()
        api.createResource(); await flush()
        api.state.draft.title = 'Unsent title'
        const previousTitle = api.selected.title
        const previousVersion = api.selected.version
        const attempts = []
        mutations.mutate = async (_id, version, action) => {
            attempts.push([action, version])
            throw { isAxiosError: true, response: { status, data: status === 422 ? { errors: { 'content.title': ['Title rejected'] } } : { message: 'Request failed' } } }
        }
        await runAutosave()
        assert.deepEqual(attempts, [['autosave', previousVersion]])
        assert.equal(api.selected.title, previousTitle)
        assert.equal(api.state.draft.title, 'Unsent title')
        assert.equal(api.dirty, true)
        assert.equal(api.state.error, '')
        assert.equal(api.state.autosaveError, false)
        assert.equal(api.state.conflict, false)
        assert.deepEqual(api.state.fieldErrors, {})

        api.save(); await flush()
        assert.deepEqual(attempts.at(-1), ['save', previousVersion], 'manual saves retain the known version for conflict protection')
        assert.ok(api.state.error)
        if (status === 422) assert.equal(api.fieldError('title'), 'Title rejected')
        if (status === 409) assert.equal(api.state.conflict, true)
    })
}

test('reopening a failed-save draft recovers it, but never overwrites a newer server version', async () => {
    for (const serverChanged of [false, true]) {
        const recovery = new Map()
        const original = workspaceHarness({ recovery, fail: action => action === 'autosave' })
        original.api.createResource(); await flush()
        original.api.state.draft.title = 'Unsent work'
        await original.api.retrySave()
        assert.equal(recovery.size, 1)
        const existing = resource()
        if (serverChanged) existing.title = 'Another manager saved this'
        const reopened = workspaceHarness({ recovery, existing })
        reopened.api.createResource(); await flush()
        assert.equal(reopened.api.state.draft.title, 'Unsent work')
        assert.equal(reopened.api.state.conflict, serverChanged)
        assert.equal(await reopened.api.retrySave(), !serverChanged)
        if (serverChanged) {
            assert.equal(reopened.calls.some(call => call[0] === 'autosave'), false)
            const again = workspaceHarness({ recovery, existing })
            again.api.createResource(); await flush()
            assert.equal(again.api.state.conflict, true)
            assert.equal(await again.api.retrySave(), false)
        } else assert.equal(reopened.api.selected.title, 'Unsent work')
    }
})

for (const publish of [false, true]) {
    test(`explicit ${publish ? 'publish' : 'save'} focuses the invalid field while showing its inline message`, async () => {
        let focused = 0
        const fields = [{ dataset: { resourceField: 'title' }, querySelector: () => ({ focus: () => focused++ }), scrollIntoView() {} }]
        const { api } = workspaceHarness({ fields })
        api.createResource(); await flush()
        api.state.draft.title = ''
        api.save(publish); await flush()
        assert.equal(focused, 1)
        assert.equal(api.fieldError('title'), 'groups.resources.workspace.validation.required')
    })
}
