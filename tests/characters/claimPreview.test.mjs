import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { computed, ref } from 'vue'

function load(file, modules = {}, globals = {}) {
    const source = readFileSync(new URL(`../../resources/js/${file}.ts`, import.meta.url), 'utf8')
    const code = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const exports = {}
    new Function('require', 'exports', ...Object.keys(globals), code)(
        id => { assert.ok(id in modules, id); return modules[id] }, exports, ...Object.values(globals),
    )
    return exports
}

const fixture = load('utils/characterClaimPreview', {
    '@/utils/characterPreview': load('utils/characterPreview'),
})
const realCharacter = {
    id: 99, lodestone_id: '12345678', name: 'Real Character', world: 'Lich',
    datacenter: 'Light', avatar_url: null, status: 'unverified',
}

function setup({ enabled = true, post, context = 'owned', status = 'unverified', transfer = null } = {}) {
    let mount, unmount, nextTimer = 0
    const calls = [], copies = [], timers = new Map()
    const canPreview = ref(enabled)
    const { useCharacterClaim } = load('composables/useCharacterClaim', {
        vue: { computed, ref, onMounted: callback => { mount = callback }, onBeforeUnmount: callback => { unmount = callback } },
        'vue-i18n': { useI18n: () => ({ t: key => key, te: () => true, locale: ref('en') }) },
        'ziggy-js': { route: (name, params) => `${name}:${JSON.stringify(params)}` },
        '@vueuse/core': { useClipboard: () => ({ copied: ref(false), copy: async value => copies.push(value) }) },
        '@/utils/characterClaimPreview': fixture,
        '@/utils/characterTransferPreview': load('utils/characterTransferPreview'),
        axios: { default: {
            isAxiosError: error => Boolean(error?.response), isCancel: () => false,
            post: async (url, data) => {
                calls.push({ url, data })
                if (post) return post(url, data)
                return { data: {
                    character: { ...realCharacter, status: url.includes('.verify') ? 'claimed' : 'unverified' },
                    code: 'FP-REAL-CODE', expires_at: '2030-01-01T12:00:00Z', context,
                } }
            },
        } },
    }, {
        setTimeout: callback => { const id = ++nextTimer; timers.set(id, callback); return id },
        clearTimeout: id => timers.delete(id),
    })
    const api = useCharacterClaim({ character: { ...realCharacter, status }, context, transfer }, canPreview)
    return { api, calls, copies, timers, canPreview, mount: () => mount(), unmount: () => unmount() }
}

test('every claim preview has Nyssa data and translated menu labels in all supported locales', () => {
    const locales = ['en', 'de', 'fr', 'ja'].map(locale =>
        JSON.parse(readFileSync(new URL(`../../lang/${locale}/characters.json`, import.meta.url), 'utf8')).claim_flow)
    for (const screen of fixture.characterClaimPreviewScreens) {
        const state = fixture.characterClaimPreview(screen, '2030-01-01T12:00:00Z')
        assert.equal(state.character.name, 'Nyssa Nightclaw')
        assert.equal(state.character.id, 0)
        for (const locale of locales) {
            assert.ok(locale.preview.screens[screen], screen)
            if (state.errorKey) assert.ok(locale.errors[state.errorKey])
        }
    }
})

test('reopening a claimed character with a pending transfer resumes decisions without issuing proof', async () => {
    const { api, calls } = setup({ status: 'claimed', transfer: { id: 8, pending: true, runs: [] } })
    await api.loadCode()
    assert.equal(api.stage.value, 'transfer')
    assert.equal(api.transfer.value.id, 8)
    assert.deepEqual(calls, [])
    api.finishTransfer()
    assert.equal(api.stage.value, 'complete')
})

test('fresh Lodestone transfer verification enters the decision screen', async () => {
    const { api } = setup({ context: 'transfer', post: async url => ({ data: {
        character: { ...realCharacter, status: url.includes('.verify') ? 'claimed' : 'unverified' },
        context: 'transfer', code: 'FP-REAL-CODE', expires_at: null, transferred: true,
        transfer: url.includes('.verify') ? { id: 8, pending: true, runs: [] } : null,
    } }) })
    await api.loadCode()
    await api.verify()
    assert.equal(api.stage.value, 'transfer')
    assert.equal(api.transfer.value.id, 8)
})

test('preview controls and actions never issue a code, verify ownership, or write to the clipboard', async () => {
    const { api, calls, copies } = setup()
    for (const screen of fixture.characterClaimPreviewScreens) {
        api.showPreview(screen)
        await api.copyCode()
        await api.verify()
        await api.loadCode()
    }
    api.showPreview('linked_verified')
    api.previewClaim()
    assert.equal(api.stage.value, 'instructions')
    assert.equal(api.character.value.status, 'verified')
    assert.deepEqual(calls, [])
    assert.deepEqual(copies, [])
})

test('exiting preview restores the live character, code, expiry, and normal claim action', async () => {
    const { api, calls } = setup()
    await api.loadCode()
    const expiry = api.expiry.value
    api.showPreview('complete')
    assert.equal(api.character.value.status, 'claimed')
    assert.equal(api.code.value, null)
    api.showPreview('live')
    assert.equal(api.character.value.id, realCharacter.id)
    assert.equal(api.stage.value, 'instructions')
    assert.equal(api.code.value, 'FP-REAL-CODE')
    assert.equal(api.expiry.value, expiry)
    await api.verify()
    assert.equal(api.stage.value, 'complete')
    assert.equal(calls.length, 2)
    assert.equal(calls[1].url, 'characters.claim.verify:99')
    assert.deepEqual(calls[1].data, { code: 'FP-REAL-CODE' })
})

test('preview cannot replace an in-flight real request and is unavailable without the development flag', async () => {
    let resolve
    const { api } = setup({ post: () => new Promise(done => { resolve = done }) })
    const loading = api.loadCode()
    api.showPreview('complete')
    assert.equal(api.previewActive.value, false)
    assert.equal(api.character.value.id, realCharacter.id)
    resolve({ data: { character: realCharacter, code: 'FP-REAL-CODE', expires_at: null } })
    await loading
    const disabled = setup({ enabled: false })
    disabled.api.showPreview('claimed_elsewhere')
    assert.equal(disabled.api.previewActive.value, false)
    assert.equal(disabled.api.character.value.id, realCharacter.id)
    assert.equal(disabled.timers.size, 0)
})

test('simulated checks finish locally, and switching screens or unmounting cancels their timers', async () => {
    const { api, timers, calls, unmount } = setup()
    api.showPreview('instructions')
    await api.verify()
    assert.equal(api.stage.value, 'checking')
    timers.values().next().value()
    assert.equal(api.stage.value, 'complete')
    api.showPreview('instructions')
    await api.verify()
    api.showPreview('expired_code')
    assert.equal(timers.size, 0)
    assert.equal(api.code.value, null)
    assert.ok(api.error.value.endsWith('expired_code'))
    api.showPreview('instructions')
    await api.verify()
    unmount()
    assert.equal(timers.size, 0)
    assert.deepEqual(calls, [])
})

test('a foreign claimed character opens the help screen without issuing or checking proof', async () => {
    const { api, calls } = setup({ context: 'already_claimed', status: 'claimed' })
    await api.loadCode()
    await api.verify()
    assert.equal(api.stage.value, 'claimed_elsewhere')
    assert.deepEqual(calls, [])
})

test('a claim completed elsewhere during verification switches to help and clears stale proof', async () => {
    const { api } = setup({ context: 'transfer', post: async url => {
        if (url.includes('.verify')) throw { response: { status: 409, data: {
            outcome: 'already_claimed', character: { ...realCharacter, status: 'claimed' },
        } } }
        return { data: { character: realCharacter, context: 'transfer', code: 'FP-TRANSFER', expires_at: null } }
    } })
    await api.loadCode()
    assert.equal(api.context.value, 'transfer')
    await api.verify()
    assert.equal(api.stage.value, 'claimed_elsewhere')
    assert.equal(api.code.value, null)
    assert.equal(api.error.value, null)
})

test('a completed foreign claim displays the transfer notice', async () => {
    const { api } = setup({ context: 'transfer', post: async url => ({ data: url.includes('.verify')
        ? { character: { ...realCharacter, status: 'claimed' }, transferred: true }
        : { character: realCharacter, context: 'transfer', code: 'FP-TRANSFER', expires_at: null },
    }) })
    await api.loadCode()
    await api.verify()
    assert.equal(api.stage.value, 'complete')
    assert.equal(api.transferred.value, true)
    assert.equal(api.context.value, 'owned')
})
