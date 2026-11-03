import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'

const dom = new JSDOM('<html><body></body></html>')
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node']) globalThis[key] = dom.window[key]
const vue = await import('vue')
const messages = { groups: Object.fromEntries(['index', 'common'].map(name => [name,
    JSON.parse(readFileSync(new URL(`../../lang/en/groups/${name}.json`, import.meta.url), 'utf8')),
])) }
const translate = (key, values = {}) => Object.entries(values).reduce(
    (text, [name, value]) => text.replaceAll(`{${name}}`, value),
    key.split('.').reduce((entry, part) => entry[part], messages),
)
const source = readFileSync(new URL('../../resources/js/components/Groups/GroupSwitcher.vue', import.meta.url), 'utf8')
const { descriptor } = parse(source)
const compiled = ts.transpileModule(compileScript(descriptor, { id: 'group-switcher', inlineTemplate: true }).content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText
const group = (id, name, role) => ({ id, name, slug: name.toLowerCase(), role, href: `/groups/${name.toLowerCase()}`,
    profile_picture_url: role === 'member' ? null : `/avatars/${name.toLowerCase()}.png`,
})

function mount(t, { guest = false } = {}) {
    const calls = []
    const page = vue.reactive({ url: '/en/groups/owned/dashboard/statistics', props: {
        locale: { current: 'en' }, navigation: { group_quick_links: guest ? { my: [], joined: [] } : {
            my: [group(1, 'Owned', 'owner'), group(2, 'Admin', 'admin'), group(3, 'Moderator', 'moderator')],
            joined: [group(4, 'Member', 'member')],
        } },
    } })
    const props = vue.reactive({ group: { slug: guest ? 'public' : 'owned', name: guest ? 'Public Group' : 'Owned' } })
    const modules = {
        vue, '@inertiajs/vue3': { usePage: () => page, router: { visit: href => calls.push(href) } },
        'vue-i18n': { useI18n: () => ({ t: translate, locale: vue.ref('en') }) },
        'ziggy-js': { route: (name, params) => `${params.locale}:${name}${params.group ? `:${params.group}` : ''}` },
    }
    const exports = {}
    new Function('require', 'exports', compiled)(id => { assert.ok(id in modules, id); return modules[id] }, exports)
    const root = document.body.appendChild(document.createElement('div'))
    let navigations = 0
    const app = vue.createApp({ render: () => vue.h(exports.default, { ...props, onNavigate: () => navigations++ }) })
    app.component('UButton', { setup: (_, { slots, attrs }) => () => vue.h('button', attrs, slots.default?.()) })
    app.component('UAvatar', vue.defineComponent({
        props: ['src', 'alt', 'size', 'icon'],
        setup: props => () => vue.h('img', { src: props.src, alt: props.alt, 'data-size': props.size }),
    }))
    app.component('UIcon', vue.defineComponent({
        props: ['name'], setup: props => () => vue.h('i', { 'data-icon': props.name }),
    }))
    app.component('UTooltip', vue.defineComponent({
        props: ['text'],
        setup: (props, { slots }) => () => vue.h('span', { 'data-tooltip': props.text }, slots.default?.()),
    }))
    app.component('UDropdownMenu', vue.defineComponent({
        props: ['open', 'items', 'ui'], emits: ['update:open'],
        setup: (props, { slots, emit }) => () => vue.h('div', [
            vue.cloneVNode(slots.default()[0], { onClick: () => emit('update:open', !props.open) }),
            props.open && vue.h('div', { role: 'menu' }, props.items.map(items => vue.h('section', items.map(item => vue.h('button', {
                role: 'menuitem', onClick: item.onSelect,
            }, [item.avatar && vue.h('img', { src: item.avatar.src, alt: item.avatar.alt }), item.label,
                item.slot && slots[`${item.slot}-trailing`]?.({ item }),
            ]))))),
        ]),
    }))
    app.mount(root)
    t.after(() => { app.unmount(); root.remove() })
    return { root, page, props, calls, navigations: () => navigations,
        trigger: () => root.querySelector('button'),
        open: async () => { root.querySelector('button').click(); await vue.nextTick() },
        items: () => [...root.querySelectorAll('[role="menuitem"]')],
    }
}

test('sorts groups by rank then name and shows avatars and membership role tooltips', async t => {
    const menu = mount(t)
    const links = menu.page.props.navigation.group_quick_links
    links.my.push(group(5, 'Another Owner', 'owner'), group(6, 'Zebra Admin', 'admin'), group(7, 'Another Moderator', 'moderator'))
    links.joined.unshift(group(8, 'Another Member', 'member'))
    assert.equal(menu.trigger().getAttribute('aria-label'), 'Switch group: Owned')
    assert.equal(menu.trigger().querySelector('img').getAttribute('src'), '/avatars/owned.png')
    assert.equal(menu.trigger().querySelector('img').getAttribute('data-size'), 'lg')
    assert.ok(menu.trigger().querySelector('[data-icon="i-lucide-chevrons-up-down"]'))
    await menu.open()
    assert.deepEqual(menu.items().map(item => item.textContent), [
        'Another Owner', 'Owned', 'Admin', 'Zebra Admin', 'Another Moderator', 'Moderator',
        'Another Member', 'Member', 'Create Group', 'Find Groups',
    ])
    const [admin, member, moderator, owner] = ['Admin', 'Member', 'Moderator', 'Owned']
        .map(name => menu.items().find(item => item.textContent === name))
    for (const [item, label, icon] of [[admin, 'Admin', 'shield'], [moderator, 'Moderator', 'shield'], [owner, 'Owner', 'crown']]) {
        assert.equal(item.querySelector('[data-tooltip]').dataset.tooltip, label)
        assert.equal(item.querySelector('[role="img"]').getAttribute('aria-label'), label)
        assert.ok(item.querySelector(`[data-icon="i-lucide-${icon}"]`))
        assert.ok(item.querySelector('[role="img"]').classList.contains('text-inherit'))
    }
    assert.equal(member.querySelector('[data-tooltip]'), null)
    assert.equal(member.querySelector('[role="img"]'), null)
    assert.equal(menu.root.querySelectorAll('[role="menu"] section').length, 2)
})

test('switching groups closes the selector, emits mobile navigation and visits the group home', async t => {
    const menu = mount(t)
    await menu.open()
    menu.items().find(item => item.textContent === 'Admin').click()
    await vue.nextTick()
    assert.deepEqual(menu.calls, ['en:groups.dashboard:admin'])
    assert.equal(menu.navigations(), 1)
    assert.equal(menu.root.querySelector('[role="menu"]'), null)

    menu.props.group = { slug: 'admin', name: 'Admin' }
    menu.page.url = '/en/groups/admin/dashboard'
    await vue.nextTick()
    assert.equal(menu.trigger().getAttribute('aria-label'), 'Switch group: Admin')
    assert.equal(menu.trigger().querySelector('[data-tooltip]').dataset.tooltip, 'Admin')
})

test('footer actions use the create and discovery pages in the current locale', async t => {
    const menu = mount(t)
    menu.page.props.locale.current = 'de'
    await menu.open()
    menu.items().find(item => item.textContent === 'Create Group').click()
    await vue.nextTick()
    await menu.open()
    menu.items().find(item => item.textContent === 'Find Groups').click()
    await vue.nextTick()
    assert.deepEqual(menu.calls, ['de:groups.create', 'de:groups.index'])
    assert.equal(menu.navigations(), 2)
})

test('public visitors see the current header and footer actions without adding nonmember groups to the options', async t => {
    const menu = mount(t, { guest: true })
    assert.equal(menu.trigger().getAttribute('aria-label'), 'Switch group: Public Group')
    assert.equal(menu.trigger().querySelector('[data-tooltip]'), null)
    await menu.open()
    assert.deepEqual(menu.items().map(item => item.textContent), ['Create Group', 'Find Groups'])
    menu.page.url = '/en/groups/public/dashboard/activities'
    await vue.nextTick()
    assert.equal(menu.root.querySelector('[role="menu"]'), null)
})
