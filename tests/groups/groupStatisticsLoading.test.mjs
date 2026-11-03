import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'
import * as dateTime from '../../resources/js/utils/dateTimeFormat.ts'

const dom = new JSDOM('<html><body></body></html>')
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node']) globalThis[key] = dom.window[key]
const vue = await import('vue')
const messages = JSON.parse(readFileSync(new URL('../../lang/en/groups/statistics.json', import.meta.url), 'utf8'))
const translate = (key, values = {}) => Object.entries(values).reduce(
    (text, [name, value]) => text.replaceAll(`{${name}}`, value),
    key.split('.').slice(2).reduce((entry, part) => entry[part], messages),
)

function component(path, dependencies = {}) {
    const filename = new URL(`../../resources/js/${path}.vue`, import.meta.url).pathname
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

const Skeleton = component('components/Groups/Statistics/GroupStatisticsSkeleton')
const Header = vue.defineComponent({
    props: ['title', 'subtitle'],
    setup: (props, { slots }) => () => vue.h('header', [vue.h('h1', props.title), slots.default?.()]),
})
const Content = vue.defineComponent({
    props: ['statistics'],
    setup: props => () => vue.h('section', { 'data-statistics': true }, String(props.statistics.summary.total_runs)),
})
const cache = (delay = 0) => ({
    cached_at: '2026-05-26T12:00:00Z', expires_at: '2026-05-27T12:00:00Z',
    refresh_cooldown_seconds: delay, can_refresh: delay === 0,
    refresh_available_at: delay ? new Date(Date.now() + delay * 1000).toISOString() : null,
})

function mount(t, initial = {}) {
    const calls = []
    const Page = component('Pages/Dashboard/Groups/Statistics', {
        '@inertiajs/vue3': { router: {
            post: (...args) => calls.push({ method: 'post', args }),
            reload: options => calls.push({ method: 'reload', options }),
        } },
        '@/components/PageHeader.vue': { default: Header },
        '@/components/Groups/Statistics/GroupStatisticsContent.vue': { default: Content },
        '@/components/Groups/Statistics/GroupStatisticsSkeleton.vue': { default: Skeleton },
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
    app.mount(root)
    t.after(() => { app.unmount(); root.remove() })
    return { props, calls, root, refresh: () => root.querySelector('header button') }
}

test('renders the page heading and skeletons before replacing them with deferred statistics', async t => {
    const { props, root, refresh } = mount(t)
    assert.equal(root.querySelector('h1').textContent, 'Statistics')
    assert.ok(root.querySelector('[role="status"][aria-busy="true"]'))
    assert.ok(root.querySelectorAll('[data-skeleton]').length > 5)
    assert.equal(refresh().disabled, true)
    assert.equal(root.querySelector('[data-statistics]'), null)

    props.statistics = { summary: { total_runs: 343 } }
    props.statistics_cache = cache()
    await vue.nextTick()
    assert.equal(root.querySelector('[role="status"]'), null)
    assert.equal(root.querySelector('[data-statistics]').textContent, '343')
    assert.equal(refresh().disabled, false)
})

test('a failed calculation offers retry and keeps skeletons visible until the retry finishes', async t => {
    const { props, root, calls, refresh } = mount(t, { statistics: null, statistics_cache: null })
    assert.match(root.querySelector('[role="alert"]').textContent, /Statistics could not be loaded/)
    assert.equal(refresh().disabled, true)
    root.querySelector('[role="alert"] button').click()
    await vue.nextTick()
    assert.deepEqual(calls[0].options.only, ['statistics', 'statistics_cache'])
    assert.ok(root.querySelector('[data-skeleton]'))

    props.statistics = { summary: { total_runs: 0 } }
    props.statistics_cache = cache()
    calls[0].options.onFinish()
    await vue.nextTick()
    assert.equal(root.querySelector('[role="alert"]'), null)
    assert.equal(root.querySelector('[data-statistics]').textContent, '0')
})

test('refresh shows skeletons through the redirected deferred load and respects the cooldown', async t => {
    const { props, root, calls, refresh } = mount(t, { statistics: { summary: { total_runs: 343 } }, statistics_cache: cache() })
    refresh().click()
    await vue.nextTick()
    assert.equal(calls[0].args[0], 'groups.dashboard.statistics.refresh:ftel')
    assert.equal(calls[0].args[2].preserveScroll, true)
    assert.ok(root.querySelector('[data-skeleton]'))
    assert.equal(refresh().disabled, true)

    props.statistics = undefined
    props.statistics_cache = undefined
    calls[0].args[2].onFinish()
    await vue.nextTick()
    assert.ok(root.querySelector('[data-skeleton]'))

    props.statistics = { summary: { total_runs: 344 } }
    props.statistics_cache = cache(300)
    await vue.nextTick()
    assert.equal(root.querySelector('[data-statistics]').textContent, '344')
    assert.equal(refresh().disabled, true)
    assert.match(refresh().textContent, /Refresh in/)

    props.statistics_cache = cache()
    await vue.nextTick()
    assert.equal(refresh().disabled, false)
})
