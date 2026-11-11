import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'

const dom = new JSDOM('<html><body></body></html>')
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node']) globalThis[key] = dom.window[key]
const vue = await import('vue')
const messages = JSON.parse(readFileSync(new URL('../../lang/en/groups/index.json', import.meta.url), 'utf8'))

function load(path, dependencies = {}) {
    const filename = fileURLToPath(new URL(`../../resources/js/${path}`, import.meta.url))
    let source = readFileSync(filename, 'utf8')
    if (path.endsWith('.vue')) {
        const { descriptor } = parse(source, { filename })
        source = compileScript(descriptor, {
            id: path, inlineTemplate: true,
            fs: { fileExists: existsSync, readFile: file => readFileSync(file, 'utf8') },
        }).content
    }
    const code = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const modules = {
        vue, 'vue-i18n': { useI18n: () => ({ t: key => key.split('.').slice(2).reduce((entry, part) => entry[part], messages) }) },
        ...dependencies,
    }
    const exports = {}
    new Function('require', 'exports', code)(id => { assert.ok(id in modules, id); return modules[id] }, exports)
    return exports
}

const previewMenu = load('composables/useDevelopmentPreviewMenu.ts')
const Menu = load('components/Shared/DevelopmentPreviewMenu.vue', {
    '@/composables/useDevelopmentPreviewMenu': previewMenu,
})
const Controls = load('components/Groups/GroupDashboardPreviewControls.vue', {
    '@/components/Shared/DevelopmentPreviewMenu.vue': Menu,
})

function mount(t, enabled = true) {
    const page = vue.reactive({ props: { theme_preview_enabled: enabled } })
    const props = vue.reactive({ group: { slug: 'owned', current_user_role: 'owner', permissions: { can_manage_group: true } } })
    const Page = load('Pages/Dashboard/Groups/CommunityDashboard.vue', {
        '@inertiajs/vue3': { usePage: () => page },
        '@/components/Groups/GroupDashboardPreviewControls.vue': Controls,
        '@/components/Groups/Dashboard/GroupHomeDashboard.vue': { default: {
            props: ['group', 'view'], setup: props => () => vue.h('div', { 'data-rendered-view': props.view }, `${props.view} dashboard`),
        } },
    }).default
    let menu
    const root = document.body.appendChild(document.createElement('div'))
    const app = vue.createApp({
        setup() {
            menu = previewMenu.provideDevelopmentPreviewMenu()
            return () => vue.h('div', [
                menu.open.value && vue.h('div', { id: 'v2-preview-content' }),
                vue.h(Page, props),
            ])
        },
    })
    app.component('UButton', vue.defineComponent({
        props: ['color', 'variant', 'type'],
        setup: (props, { slots, attrs }) => () => vue.h('button', { ...attrs, type: props.type }, slots.default?.()),
    }))
    app.mount(root)
    t.after(() => { app.unmount(); root.remove() })
    return { page, props, menu, root,
        button: label => [...root.querySelectorAll('button')].find(button => button.textContent === label),
        view: () => root.querySelector('[data-dashboard-view]').dataset.dashboardView,
    }
}

test('development view controls live in the dropdown and switch views without changing membership or permissions', async t => {
    const { page, props, menu, root, button, view } = mount(t)
    assert.equal(root.querySelector('button'), null)
    assert.equal(view(), 'staff')
    menu.open.value = true
    await vue.nextTick()
    assert.equal(button('Staff View').getAttribute('aria-pressed'), 'true')
    assert.ok(root.querySelector('#v2-preview-content').contains(button('User View')))

    button('User View').click()
    await vue.nextTick()
    assert.equal(view(), 'user')
    assert.equal(button('User View').getAttribute('aria-pressed'), 'true')
    assert.equal(menu.controls.value.active, true)
    assert.equal(props.group.current_user_role, 'owner')
    assert.equal(props.group.permissions.can_manage_group, true)
    assert.ok(root.querySelector('[data-rendered-view="user"]'))

    button('Staff View').click()
    await vue.nextTick()
    assert.equal(view(), 'staff')
    assert.equal(menu.controls.value.active, false)
    assert.ok(root.querySelector('[data-rendered-view="staff"]'))

    props.group = { slug: 'joined', current_user_role: 'member', permissions: { can_manage_group: false } }
    await vue.nextTick()
    assert.equal(view(), 'user')
    button('Staff View').click()
    await vue.nextTick()
    assert.equal(view(), 'staff')
    assert.equal(props.group.current_user_role, 'member')
    assert.equal(props.group.permissions.can_manage_group, false)

    page.props.theme_preview_enabled = false
    await vue.nextTick()
    assert.equal(view(), 'user')
    assert.equal(menu.controls.value, null)
    assert.equal(menu.open.value, false)
    assert.equal(root.querySelector('button'), null)
})

test('the staff homepage renders without preview controls when development previews are disabled', async t => {
    const { menu, root, view } = mount(t, false)
    menu.open.value = true
    await vue.nextTick()
    assert.equal(menu.controls.value, null)
    assert.equal(root.querySelector('button'), null)
    assert.equal(view(), 'staff')
    assert.equal(root.querySelector('[data-dashboard-view]').textContent, 'staff dashboard')
})
