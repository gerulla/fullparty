import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { computed, reactive, ref } from 'vue'
import { AxiosError, AxiosHeaders } from 'axios'

function load(relative, modules = {}) {
    const code = ts.transpileModule(readFileSync(new URL(`../../resources/js/${relative}.ts`, import.meta.url), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const exports = {}
    new Function('require', 'exports', code)(id => {
        if (id in modules) return modules[id]
        assert.ok(id.startsWith('@/utils/'), id)
        return load(id.slice(2))
    }, exports)
    return exports
}

const helpers = load('utils/groupCreateSubmission')
function setup({ images = true } = {}) {
    const calls = [], remembered = [], listeners = new Map()
    let dispose
    const router = {
        post: (url, data, options) => calls.push({ url, data, options }),
        remember: (...args) => remembered.push(args),
        on: (name, callback) => { listeners.set(name, callback); return () => listeners.delete(name) },
    }
    const draft = reactive({
        groupType: 'community', step: 'joining', region: 'EU', datacenter: 'Light',
        identity: { name: '  Aurora Adventurers  ', slug: 'aurorax', description: 'Weekly runs' },
        fit: { experience_expectation: 'casual', voice_expectation: 'optional', preferred_languages: ['en'] },
        focus: { primary_focuses: ['progression'], tags: ['Friendly'] },
        joining: { join_mode: 'open', is_visible: false },
        activity: { active_timezone: 'Europe/London', active_days: ['fri'], active_start_time: '22:00', active_end_time: '05:00', daysPreset: 'custom', timePreset: 'custom', customDays: ['fri'], customTime: { start: '22:00', end: '05:00' } },
    })
    const files = { profile_picture: images ? new File(['test'], 'avatar.png', { type: 'image/png' }) : null, banner_image: null }
    const artwork = { files, canContinue: true }
    const api = load('composables/useGroupCreateSubmission', {
        vue: { computed, reactive, ref, onScopeDispose: callback => { dispose = callback } },
        '@inertiajs/vue3': { router }, 'ziggy-js': { route: name => name },
        axios: { isAxiosError: value => value instanceof AxiosError },
    }).useGroupCreateSubmission(draft, artwork, async step => { draft.step = step })
    function invalid(status, own = true) {
        let prevented = false
        listeners.get('invalid')?.({
            detail: { response: { status, config: { headers: new AxiosHeaders(own ? calls.at(-1).options.headers : {}) } } },
            preventDefault: () => { prevented = true },
        })
        return prevented
    }
    return { api, draft, artwork, calls, remembered, listeners, invalid, dispose: () => dispose() }
}

test('creation sends all steps, original files, booleans and overnight times once', () => {
    const { api, calls, artwork } = setup()
    api.submit()
    api.submit()
    assert.equal(calls.length, 1)
    assert.equal(api.processing, true)
    const { data, options } = calls[0]
    assert.equal(data.name, 'Aurora Adventurers')
    assert.equal(data.datacenter, 'Light')
    assert.equal(data.group_type, 'community')
    assert.equal(data.join_mode, 'open')
    assert.equal(data.is_visible, false)
    assert.equal(data.profile_picture, artwork.files.profile_picture)
    assert.deepEqual(data.preferred_languages, ['en'])
    assert.deepEqual(data.primary_focuses, ['progression'])
    assert.deepEqual(data.tags, ['Friendly'])
    assert.deepEqual(data.active_days, ['fri'])
    assert.equal(data.active_start_time, '22:00')
    assert.equal(data.active_end_time, '05:00')
    assert.equal(data.daysPreset, undefined)
    assert.equal(data.customTime, undefined)
    assert.equal(options.forceFormData, true)
    assert.equal(options.preserveState, true)
    assert.equal(api.uploadPercent, 0)
    options.onProgress({ percentage: 61 })
    assert.equal(api.uploadPercent, 61)
    options.onProgress({ percentage: 100 })
    assert.equal(api.uploadPercent, 100)
})

test('validation returns to the earliest relevant step and corrections skip unaffected steps', async () => {
    const { api, draft, calls, artwork, listeners } = setup()
    const file = artwork.files.profile_picture
    api.submit()
    calls[0].options.onError({ slug: 'This slug is taken.', 'tags.0': 'Choose a different tag.', 'active_days.0': 'Invalid day.' })
    calls[0].options.onFinish()
    assert.equal(draft.step, 'details')
    assert.deepEqual(api.stepErrors, { slug: 'This slug is taken.' })
    assert.equal(artwork.files.profile_picture, file)
    assert.equal(draft.joining.is_visible, false)
    assert.equal(listeners.size, 0)
    draft.identity.slug = 'aurorayy'
    await api.advance('artwork')
    assert.equal(draft.step, 'focus')
    await api.advance('activity')
    assert.equal(draft.step, 'activity')
    await api.advance('joining')
    assert.equal(draft.step, 'joining')
    api.submit()
    assert.equal(calls.length, 2)
    assert.equal(calls[1].data.slug, 'aurorayy')
})

test('nested fields map to their step and unknown errors remain visible on joining', () => {
    for (const [field, step] of Object.entries({ group_type: 'type', datacenter: 'datacenter', 'preferred_languages.1': 'fit', profile_picture: 'artwork', 'primary_focuses.0': 'focus', 'active_days.2': 'activity', quota: 'joining' })) {
        assert.equal(helpers.groupCreateErrorStep(field), step)
    }
    const { api, calls, draft } = setup()
    api.submit()
    calls[0].options.onError({ quota: 'You reached your group limit.' })
    assert.equal(draft.step, 'joining')
    assert.deepEqual(api.stepErrors, { quota: 'You reached your group limit.' })
})

for (const [status, key, step] of [[500, 'server_error', 'joining'], [503, 'server_error', 'joining'], [413, 'upload_too_large', 'artwork'], [419, 'session_expired', 'joining'], [403, 'forbidden', 'joining'], [429, 'too_many_requests', 'joining']]) {
    test(`HTTP ${status} preserves the draft and permits a deliberate retry`, () => {
        const { api, calls, invalid, draft, listeners } = setup()
        api.submit()
        assert.equal(invalid(status), true)
        calls[0].options.onFinish()
        assert.equal(api.currentFailure, `groups.index.create_flow.submission.${key}`)
        assert.equal(api.processing, false)
        assert.equal(draft.step, step)
        assert.equal(draft.identity.slug, 'aurorax')
        assert.equal(listeners.size, 0)
        api.submit()
        assert.equal(calls.length, 2)
        assert.equal(api.currentFailure, null)
    })
}

test('network errors are handled only for this creation request', () => {
    const { api, calls, listeners, invalid } = setup({ images: false })
    api.submit()
    assert.equal(api.uploadPercent, null)
    assert.equal(invalid(500, false), false)
    assert.equal(api.processing, true)
    let prevented = false
    const error = new AxiosError('Network error', 'ERR_NETWORK', { headers: new AxiosHeaders(calls[0].options.headers) })
    listeners.get('exception')({ detail: { exception: error }, preventDefault: () => { prevented = true } })
    assert.equal(prevented, true)
    assert.equal(api.currentFailure, 'groups.index.create_flow.submission.network_error')
    calls[0].options.onFinish()
    assert.equal(listeners.size, 0)
})

test('successful dashboard navigation clears the remembered draft; validation and login redirects do not', () => {
    const { api, calls, remembered, listeners } = setup()
    api.submit()
    calls[0].options.onBeforeUpdate({ url: '/en/groups/create' })
    calls[0].options.onBeforeUpdate({ url: '/en/auth/login' })
    assert.equal(remembered.length, 0)
    calls[0].options.onBeforeUpdate({ url: '/en/groups/aurorax/dashboard' })
    assert.deepEqual(remembered, [[undefined, 'groups.create']])
    calls[0].options.onFinish()
    assert.equal(api.processing, false)
    assert.equal(listeners.size, 0)
})

test('invalid artwork never submits; cancellation and disposal clean up listeners', () => {
    const { api, artwork, calls, draft, listeners, dispose } = setup()
    artwork.canContinue = false
    api.submit()
    assert.equal(calls.length, 0)
    assert.equal(draft.step, 'artwork')
    artwork.canContinue = true
    api.submit()
    calls[0].options.onCancel()
    calls[0].options.onFinish()
    assert.equal(api.processing, false)
    assert.equal(listeners.size, 0)
    api.submit()
    dispose()
    assert.equal(listeners.size, 0)
})
