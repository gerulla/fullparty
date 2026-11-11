import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'
import * as dateTime from '../../resources/js/utils/dateTimeFormat.ts'

const dom = new JSDOM('<html><body></body></html>')
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node']) globalThis[key] = dom.window[key]
const vue = await import('vue')
const messages = JSON.parse(readFileSync(new URL('../../lang/en/groups/leaderboard.json', import.meta.url), 'utf8'))
const translate = (key, values = {}) => Object.entries(values).reduce(
    (text, [name, value]) => text.replaceAll(`{${name}}`, value),
    key.split('.').slice(2).reduce((entry, part) => entry[part], messages),
)

function component(path, dependencies = {}) {
    const filename = fileURLToPath(new URL(`../../resources/js/${path}.vue`, import.meta.url))
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename })
    const script = compileScript(descriptor, { id: path, inlineTemplate: true })
    const code = ts.transpileModule(script.content, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const modules = {
        vue, 'vue-i18n': { useI18n: () => ({ t: translate, locale: vue.ref('en') }) },
        '@/utils/dateTimeFormat': dateTime,
        'ziggy-js': { route: (name, slug) => `${name}:${slug}` },
        ...dependencies,
    }
    const exports = {}
    new Function('require', 'exports', code)(name => {
        assert.ok(name in modules, `Unexpected dependency ${name}`)
        return modules[name]
    }, exports)
    return exports.default
}

const Skeleton = component('components/Groups/Leaderboard/GroupLeaderboardSkeleton')
const Header = vue.defineComponent({
    props: ['title', 'subtitle'],
    setup: (props, { slots }) => () => vue.h('header', [vue.h('h1', props.title), slots.default?.()]),
})
const RankingPanel = component('components/Groups/Leaderboard/GroupLeaderboardRankingPanel')
const Content = component('components/Groups/Leaderboard/GroupLeaderboardContent', {
    './GroupLeaderboardRankingPanel.vue': { default: RankingPanel },
})
const payload = total => ({
    generated_at: new Date().toISOString(),
    summary: { total_participations: total, ranked_participants: 0, raid_leader_participations: 0, host_participations: 0 },
    rankings: { overall: [], raid_leaders: [], hosts: [], host_success: [] },
})
function rankingComposable(axios) {
    const source = readFileSync(new URL('../../resources/js/composables/useGroupLeaderboardRankings.ts', import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = { vue, axios: { default: axios }, 'ziggy-js': { route: (name, slug) => `${name}:${slug}` } }
    const exports = {}
    new Function('require', 'exports', code)(name => modules[name], exports)
    return exports
}
const cache = (delay = 0) => ({
    cached_at: '2026-05-26T12:00:00Z', expires_at: '2026-05-27T12:00:00Z',
    refresh_cooldown_seconds: delay, can_refresh: delay === 0,
    refresh_available_at: delay ? new Date(Date.now() + delay * 1000).toISOString() : null,
})

function mount(t, initial = {}) {
    const calls = []
    const requests = []
    const rankings = rankingComposable({ get: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })) })
    const Page = component('Pages/Dashboard/Groups/Leaderboard', {
        '@/composables/useGroupLeaderboardRankings': rankings,
        '@inertiajs/vue3': { router: {
            post: (...args) => calls.push({ method: 'post', args }),
            reload: options => calls.push({ method: 'reload', options }),
        } },
        '@/components/PageHeader.vue': { default: Header },
        '@/components/Groups/Leaderboard/GroupLeaderboardContent.vue': { default: Content },
        '@/components/Groups/Leaderboard/GroupLeaderboardSkeleton.vue': { default: Skeleton },
    })
    const props = vue.reactive({ group: { slug: 'ftel', name: 'FTEL' }, ...initial })
    const root = document.body.appendChild(document.createElement('div'))
    const app = vue.createApp({ render: () => vue.h(Page, props) })
    app.component('UButton', vue.defineComponent({
        props: ['label', 'disabled', 'loading'],
        setup: (props, { attrs }) => () => vue.h('button', { ...attrs, disabled: props.disabled }, props.label),
    }))
    app.component('UCard', { setup: (_, { slots }) => () => vue.h('article', [slots.header?.(), slots.default?.()]) })
    app.component('USkeleton', { render: () => vue.h('div', { 'data-skeleton': true }) })
    app.component('UAlert', vue.defineComponent({
        props: ['title', 'description'],
        setup: (props, { slots }) => () => vue.h('div', { role: 'alert' }, [props.title, props.description, slots.actions?.()]),
    }))
    for (const name of ['UIcon', 'UBadge', 'UTooltip']) {
        app.component(name, { setup: (_, { slots }) => () => vue.h('span', slots.default?.()) })
    }
    app.component('UTabs', vue.defineComponent({
        props: ['modelValue', 'items'], emits: ['update:modelValue'],
        setup: (props, { emit }) => () => vue.h('select', { value: props.modelValue,
            onChange: event => emit('update:modelValue', event.target.value),
        }, props.items.map(item => vue.h('option', { value: item.value }, item.label))),
    }))
    app.mount(root)
    t.after(() => { app.unmount(); root.remove() })
    return { props, calls, requests, root, refresh: () => root.querySelector('header button') }
}

test('renders the page heading and skeletons before replacing them with deferred leaderboard', async t => {
    const { props, root, refresh } = mount(t)
    assert.equal(root.querySelector('h1').textContent, 'Leaderboard')
    assert.ok(root.querySelector('[role="status"][aria-busy="true"]'))
    assert.ok(root.querySelectorAll('[data-skeleton]').length > 5)
    assert.equal(refresh().disabled, true)
    assert.equal(root.querySelectorAll('select').length, 0)

    props.leaderboard = payload(343)
    props.leaderboard_cache = cache()
    await vue.nextTick()
    assert.equal(root.querySelector('[role="status"]'), null)
    assert.match(root.textContent, /343/)
    assert.equal(root.querySelectorAll('select').length, 6)
    assert.equal(refresh().disabled, false)
})

test('a failed calculation offers retry and keeps skeletons visible until the retry finishes', async t => {
    const { props, root, calls, refresh } = mount(t, { leaderboard: null, leaderboard_cache: null })
    assert.match(root.querySelector('[role="alert"]').textContent, /Leaderboard could not be loaded/)
    assert.equal(refresh().disabled, true)
    root.querySelector('[role="alert"] button').click()
    await vue.nextTick()
    assert.deepEqual(calls[0].options.only, ['leaderboard', 'leaderboard_cache'])
    assert.ok(root.querySelector('[data-skeleton]'))

    props.leaderboard = payload(0)
    props.leaderboard_cache = cache()
    calls[0].options.onFinish()
    await vue.nextTick()
    assert.equal(root.querySelector('[role="alert"]'), null)
    assert.equal(root.querySelectorAll('select').length, 6)
})

test('refresh shows skeletons through the redirected deferred load and respects the cooldown', async t => {
    const { props, root, calls, refresh } = mount(t, { leaderboard: payload(343), leaderboard_cache: cache() })
    refresh().click()
    await vue.nextTick()
    assert.equal(calls[0].args[0], 'groups.dashboard.leaderboard.refresh:ftel')
    assert.equal(calls[0].args[2].preserveScroll, true)
    assert.ok(root.querySelector('[data-skeleton]'))
    assert.equal(refresh().disabled, true)

    props.leaderboard = undefined
    props.leaderboard_cache = undefined
    calls[0].args[2].onFinish()
    await vue.nextTick()
    assert.ok(root.querySelector('[data-skeleton]'))

    props.leaderboard = payload(344)
    props.leaderboard_cache = cache(300)
    await vue.nextTick()
    assert.match(root.textContent, /344/)
    assert.equal(refresh().disabled, true)
    assert.match(refresh().textContent, /Refresh in/)

    props.leaderboard_cache = cache()
    await vue.nextTick()
    assert.equal(refresh().disabled, false)
})

const entry = name => ({ rank: 1, character: { id: 1, name, world: 'Odin', datacenter: 'Light' }, count: 1, latest_activity_at: null })
async function settle() { await new Promise(resolve => setImmediate(resolve)); await vue.nextTick() }
async function choose(select, value) {
    select.value = value
    select.dispatchEvent(new window.Event('change'))
    await vue.nextTick()
}

test('period and limit changes show row skeletons, ignore stale responses and reuse cached rankings', async t => {
    const { root, requests } = mount(t, { leaderboard: payload(4), leaderboard_cache: cache() })
    const [limit, period] = root.querySelectorAll('select')
    await choose(period, 'past_30_days')
    assert.ok(root.querySelector('[role="status"]'))
    assert.deepEqual(requests[0].options.params, { category: 'overall', period: 'past_30_days', limit: '10' })
    await choose(limit, '100')
    assert.equal(requests[0].options.signal.aborted, true)
    requests[0].resolve({ data: { data: [entry('Stale player')] } })
    await settle()
    assert.doesNotMatch(root.textContent, /Stale player/)
    assert.ok(root.querySelector('[role="status"]'))
    requests[1].resolve({ data: { data: [entry('Current player')] } })
    await settle()
    assert.equal(root.querySelector('[role="status"]'), null)
    assert.match(root.textContent, /Current player/)

    await choose(period, 'all_time')
    const abortedRequest = requests[2]
    await choose(period, 'past_30_days')
    assert.equal(abortedRequest.options.signal.aborted, true)
    assert.equal(requests.length, 3)
    assert.match(root.textContent, /Current player/)
})

test('failed ranking requests offer retry for the selected filters', async t => {
    const { root, requests } = mount(t, { leaderboard: payload(4), leaderboard_cache: cache() })
    await choose(root.querySelector('select'), 'all')
    requests[0].reject(new Error('Offline'))
    await settle()
    assert.equal(root.querySelector('[role="status"]'), null)
    assert.match(root.querySelector('[role="alert"]').textContent, /Leaderboard could not be loaded/)
    root.querySelector('[role="alert"] button').click()
    await vue.nextTick()
    assert.ok(root.querySelector('[role="status"]'))
    assert.equal(requests[1].options.params.limit, 'all')
    requests[1].resolve({ data: { data: [] } })
    await settle()
    assert.equal(root.querySelector('[role="alert"]'), null)
    assert.equal(root.querySelector('[role="status"]'), null)
})

test('refresh retains filters, cancels old requests and invalidates the ranking cache', async t => {
    const { root, props, requests, calls, refresh } = mount(t, { leaderboard: payload(4), leaderboard_cache: cache() })
    await choose(root.querySelector('select'), 'all')
    refresh().click()
    props.leaderboard = undefined
    props.leaderboard_cache = undefined
    calls[0].args[2].onFinish()
    await vue.nextTick()
    assert.equal(requests[0].options.signal.aborted, true)
    props.leaderboard = payload(5)
    props.leaderboard_cache = cache(300)
    await vue.nextTick()
    assert.equal(root.querySelector('select').value, 'all')
    assert.equal(requests[1].options.params.limit, 'all')
    requests[0].resolve({ data: { data: [entry('Old generation')] } })
    requests[1].resolve({ data: { data: [entry('New generation')] } })
    await settle()
    assert.match(root.textContent, /New generation/)
    assert.doesNotMatch(root.textContent, /Old generation/)
})
