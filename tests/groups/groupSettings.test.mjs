import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'
import * as vue from 'vue'

function load(path, modules) {
    const filename = fileURLToPath(new URL(`../../resources/js/${path}`, import.meta.url))
    let source = readFileSync(filename, 'utf8')
    if (path.endsWith('.vue')) source = compileScript(parse(source, { filename }).descriptor, { id: path }).content
    const code = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const exports = {}
    new Function('require', 'exports', code)(id => {
        assert.ok(id in modules, id)
        return modules[id]
    }, exports)
    return exports
}

test('group settings deep links, back navigation and unavailable categories stay in sync', async t => {
    const baseUrl = '/en/groups/storm/dashboard/settings'
    const page = vue.reactive({ component: 'Dashboard/Groups/Settings/Index', url: `${baseUrl}?section=features`, props: { group: { id: 15 } } })
    const sections = vue.ref(['general', 'invites', 'features', 'ownership', 'danger_zone'])
    const calls = []
    const remembered = []
    const originalWindow = globalThis.window
    globalThis.window = { location: { origin: 'https://fullparty.test' } }
    t.after(() => { globalThis.window = originalWindow })
    const apiModule = load('composables/useGroupSettingsNavigation.ts', {
        vue,
        '@vueuse/core': { breakpointsTailwind: {}, useBreakpoints: () => ({ smaller: () => vue.ref(true) }) },
        '@inertiajs/vue3': {
            usePage: () => page,
            useRemember: (state, key) => { remembered.push(key); return state },
            router: { push: options => { calls.push(options); page.url = options.url } },
        },
    })
    const scope = vue.effectScope()
    t.after(() => scope.stop())
    const api = scope.run(() => apiModule.useGroupSettingsNavigation(15, () => sections.value))
    assert.deepEqual(remembered, ['groups.15.settings.navigation'])
    assert.deepEqual({ ...api.navigation }, { activeSection: 'features', sectionOpen: true })

    api.showAllSettings()
    await vue.nextTick()
    assert.equal(page.url, baseUrl)
    assert.equal(api.navigation.sectionOpen, false)
    api.openSection('invites')
    await vue.nextTick()
    assert.equal(api.navigation.activeSection, 'invites')
    assert.equal(api.navigation.sectionOpen, true)
    assert.equal(calls.at(-1).preserveState, true)

    // Browser Back reaches the category list instead of immediately reopening the detail.
    page.url = baseUrl
    await vue.nextTick()
    assert.equal(api.navigation.sectionOpen, false)
    page.url = `${baseUrl}?section=invites`
    await vue.nextTick()
    sections.value = sections.value.filter(section => section !== 'invites')
    await vue.nextTick()
    assert.deepEqual({ ...api.navigation }, { activeSection: 'general', sectionOpen: false })
    const visits = calls.length
    api.openSection('invites')
    assert.equal(calls.length, visits)
})

test('saving features uses newly saved general settings instead of overwriting them with mount-time values', () => {
    const props = vue.reactive({ group: {
        slug: 'storm', name: 'Old name', description: 'Old description', discord_invite_url: null,
        datacenter: 'Light', join_mode: 'invite_only', is_visible: true,
        features: {}, permissions: { can_update_group_settings: true },
    } })
    const requests = []
    const component = load('components/Groups/GroupFeatureSettings.vue', {
        'vue': vue,
        'vue-i18n': { useI18n: () => ({ t: key => key }) },
        '@nuxt/ui/composables': { useToast: () => ({ add() {} }) },
        'ziggy-js': { route: (name, group) => `${name}:${group}` },
        '@inertiajs/vue3': { useForm: data => {
            const form = vue.reactive({ ...data, transform: transform => ({
                post: url => requests.push({ url, data: transform({ features: { ...form.features } }) }),
            }) })
            return form
        } },
    }).default
    const { form, submit } = component.setup(props, { expose() {} })
    Object.assign(props.group, { name: 'Updated name', description: 'Updated description', is_visible: false })
    form.features.statistics_enabled = false
    submit()
    assert.equal(requests[0].url, 'groups.dashboard.settings.update:storm')
    assert.equal(requests[0].data.name, 'Updated name')
    assert.equal(requests[0].data.description, 'Updated description')
    assert.equal(requests[0].data.is_visible, false)
    assert.equal(requests[0].data.features.statistics_enabled, false)
    props.group.permissions.can_update_group_settings = false
    submit()
    assert.equal(requests.length, 1)
})

test('Discord loads settings before coverage, refreshes after unlink and ignores cancelled responses', async t => {
    const requests = []
    const enabled = vue.ref(true)
    const apiModule = load('composables/useGroupDiscordSettingsData.ts', {
        vue,
        axios: { default: { get: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })), isCancel: () => false } },
    })
    const renderer = vue.createRenderer({
        insert() {}, remove() {}, patchProp() {}, setText() {}, setElementText() {},
        createElement: () => ({}), createText: () => ({}), createComment: () => ({}),
        parentNode: () => null, nextSibling: () => null,
    })
    let api
    const app = renderer.createApp({ setup() {
        api = apiModule.useGroupDiscordSettingsData(() => '/groups/storm/discord-integration', () => enabled.value)
        return () => null
    } })
    app.mount({})
    let mounted = true
    t.after(() => { if (mounted) app.unmount() })
    assert.equal(api.loading.value, true)
    assert.equal(api.data.value, null)
    requests[0].resolve({ data: { integration: { id: 8 }, snapshot: { guild_name: 'Storm' } } })
    await vue.nextTick()
    assert.equal(api.loading.value, false)
    assert.equal(api.data.value.snapshot.guild_name, 'Storm')
    assert.equal(api.data.value.membershipCoverage, undefined)
    assert.deepEqual(requests[1].options.params, { section: 'coverage' })

    // Unlinking refreshes the setup view even if the older stats request finishes later.
    const reloaded = api.reload()
    assert.equal(requests[1].options.signal.aborted, true)
    requests[2].resolve({ data: { integration: null, snapshot: null } })
    await reloaded
    requests[1].resolve({ data: { membershipCoverage: { member_count: 99 } } })
    await vue.nextTick()
    assert.equal(api.data.value.integration, null)
    assert.equal(api.data.value.membershipCoverage, undefined)
    assert.equal(requests.length, 3)

    const failed = api.reload()
    requests[3].reject(new Error('Offline'))
    await failed
    assert.equal(api.failed.value, true)
    enabled.value = false
    await vue.nextTick()
    await api.reload()
    assert.equal(requests.length, 4)
    enabled.value = true
    await vue.nextTick()
    assert.equal(requests.length, 5)
    app.unmount()
    mounted = false
    assert.equal(requests[4].options.signal.aborted, true)
    requests[4].resolve({ data: { integration: { id: 9 } } })
    await vue.nextTick()
    assert.equal(api.data.value.integration, null)
})


test('Discord preview is development-only and cannot submit bot settings or override changes', t => {
    const page = vue.reactive({ props: { theme_preview_enabled: true } })
    const preview = load('composables/useGroupDiscordPreview.ts', {
        vue, '@inertiajs/vue3': { usePage: () => page },
    }).useGroupDiscordPreview()
    preview.active.value = true
    assert.equal(preview.active.value, true)
    page.props.theme_preview_enabled = false
    assert.equal(preview.active.value, false)
    preview.active.value = true
    assert.equal(preview.active.value, false)

    const fixture = load('utils/groupDiscordPreview.ts', {}).groupDiscordPreview({ id: 15, name: 'Storm', slug: 'storm' })
    const props = vue.reactive({ ...fixture, preview: true })
    const writes = []
    const apiModule = load('composables/useGroupDiscordSettingsForm.ts', {
        vue,
        'vue-i18n': { useI18n: () => ({ t: key => key }) },
        '@nuxt/ui/composables': { useToast: () => ({ add() {} }) },
        'ziggy-js': { route: (name, slug) => `${name}:${slug}` },
        '@inertiajs/vue3': { useForm: initial => {
            let defaults = initial
            return vue.reactive({ ...initial,
                defaults: value => { defaults = value },
                reset() { Object.assign(this, defaults) },
                transform: transform => ({ put: url => writes.push(url) }),
            })
        } },
    })
    const scope = vue.effectScope()
    t.after(() => scope.stop())
    const form = scope.run(() => apiModule.useGroupDiscordSettingsForm(props, async () => {}, () => {}))
    form.saveSettings()
    form.updateOverrideRole(0, 'preview-raider')
    assert.deepEqual(writes, [])
    props.preview = false
    form.saveSettings()
    assert.deepEqual(writes, ['groups.dashboard.discord-integration.settings.update:storm'])
})
