import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'

process.env.TZ = 'America/Los_Angeles'
const dom = new JSDOM('<html><body></body></html>')
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node']) globalThis[key] = dom.window[key]
const vue = await import('vue')
const messages = {
    runs: JSON.parse(readFileSync(new URL('../../lang/en/runs.json', import.meta.url), 'utf8')),
    reports: JSON.parse(readFileSync(new URL('../../lang/en/reports.json', import.meta.url), 'utf8')),
    general: JSON.parse(readFileSync(new URL('../../lang/en/general.json', import.meta.url), 'utf8')),
    notifications: JSON.parse(readFileSync(new URL('../../lang/en/notifications.json', import.meta.url), 'utf8')),
    groups: Object.fromEntries(['dashboard', 'activities', 'notifications', 'common', 'index'].map(name => [name,
        JSON.parse(readFileSync(new URL(`../../lang/en/groups/${name}.json`, import.meta.url), 'utf8')),
    ])),
    navigation: JSON.parse(readFileSync(new URL('../../lang/en/navigation.json', import.meta.url), 'utf8')),
    dashboard: JSON.parse(readFileSync(new URL('../../lang/en/dashboard.json', import.meta.url), 'utf8')),
}
const translate = (key, values = {}) => {
    let message = key.split('.').reduce((entry, part) => { assert.ok(entry && part in entry, key); return entry[part] }, messages)
    if (typeof values === 'number') {
        const forms = message.split(' | ')
        message = forms[Math.min(values, 2)] ?? forms[0]
        values = { count: values }
    }
    return Object.entries(values).reduce((text, [name, value]) => text.replaceAll(`{${name}}`, value), message)
}

function context() {
    const page = vue.reactive({ url: '/en/groups/raiders/dashboard', props: { auth: { user: { id: 7, time_display_mode: 'server' } }, locale: { fallback: 'en' } } })
    const now = vue.ref(new Date('2026-11-02T12:00:00Z'))
    const locale = vue.ref('en')
    const calls = []
    const confirmations = []
    const reports = []
    const resizeObservers = new Set()
    let closeConfirmation
    let postResult = 'success'
    const modules = {
        vue,
        'vue-i18n': { useI18n: () => ({ t: translate, locale }) },
        '@vueuse/core': {
            useNow: () => now,
            useResizeObserver: (_, callback) => {
                resizeObservers.add(callback)
                const stop = () => resizeObservers.delete(callback)
                vue.onScopeDispose(stop)
                return { stop }
            },
        },
        'ziggy-js': { route: (name, params) => name === 'dashboard.runs.index'
            ? `/${params.locale}/dashboard/runs?activity_type_id=${params.activity_type_id}&group=${params.group}`
            : name === 'groups.dashboard.settings' ? `/${params.locale}/groups/${params.group}/dashboard/settings` : `${name}:${params}` },
        '@inertiajs/vue3': {
            usePage: () => page,
            Link: { props: ['href'], setup: (props, { slots }) => () => vue.h('a', { href: props.href }, slots.default?.()) },
            router: {
                visit: url => calls.push({ url }),
                post: (url, data, options) => {
                    calls.push({ url, data })
                    if (postResult === 'success') options.onSuccess()
                    if (postResult === 'error') options.onError()
                    options.onFinish()
                },
            },
        },
        '@/composables/useConfirmationModal': { useConfirmationModal: () => ({
            open: request => { confirmations.push(request); return new Promise(resolve => { closeConfirmation = resolve }) },
        }) },
        '@/composables/useReportModal': { useReportModal: () => ({
            open: (target, options) => { reports.push({ target, options }); return Promise.resolve(false) },
        }) },
        '@/components/Groups/GroupNotificationPreferencesModal.vue': { default: {
            props: ['open', 'group'],
            setup: props => () => props.open ? vue.h('div', { role: 'dialog' }, `Notifications: ${props.group.name}`) : null,
        } },
    }
    function load(path) {
        const id = `@/${path.replace(/\.ts$/, '')}`
        if (id in modules) return modules[id]
        const filename = new URL(`../../resources/js/${path}`, import.meta.url).pathname
        let source = readFileSync(filename, 'utf8')
        if (path.endsWith('.vue')) {
            const { descriptor } = parse(source, { filename })
            source = compileScript(descriptor, { id: path, inlineTemplate: true,
                fs: { fileExists: existsSync, readFile: file => readFileSync(file, 'utf8') },
            }).content
        }
        const code = ts.transpileModule(source, {
            compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
        }).outputText
        const exports = {}
        new Function('require', 'exports', code)(dependency => {
            if (dependency in modules) return modules[dependency]
            assert.ok(dependency.startsWith('@/'), dependency)
            return load(dependency.slice(2) + (dependency.endsWith('.vue') ? '' : '.ts'))
        }, exports)
        modules[id] = exports
        return exports
    }
    return { load, page, now, locale, calls, confirmations, reports,
        resize: () => resizeObservers.forEach(callback => callback()),
        closeConfirmation: value => closeConfirmation(value), setPostResult: value => { postResult = value },
    }
}

const activity = (id, starts_at, extra = {}) => ({
    id, starts_at, title: `Run ${id}`, status: 'scheduled', duration_hours: 2,
    activity_type: { id: 10, draft_name: { en: 'Chaotic Alliance' }, slug: 'chaotic' },
    banner_image_url: '/run-banner.webp', small_image_url: null, is_public: true, application_count: 3,
    applicant_avatars: ['/applicant-1.webp', null, '/applicant-3.webp'], participation_status: null,
    organized_by: { id: 7, name: 'Run Host', avatar_url: null },
    target_prog_point_label: { en: 'Clear', fr: 'Victoire' }, target_prog_point_key: 'clear',
    links: { view: `/runs/${id}`, apply: null }, ...extra,
})
const group = () => ({
    id: 15, slug: 'storm', name: 'Storm Keepers', group_type: 'community', description: 'Late-night progression.', is_visible: true,
    banner_image_url: '/banner.webp', discord_invite_url: 'https://discord.gg/fullparty',
    profile_picture_url: '/group-avatar.webp',
    owner: { id: 7, name: 'Group Owner', avatar_url: null },
    datacenter: 'Light', region: 'EU', preferred_languages: ['en', 'de'],
    active_days: ['mon', 'fri'], active_timezone: 'Europe/Dublin', active_start_time: '19:00:00', active_end_time: '22:00:00',
    experience_expectation: 'beginner_friendly', voice_expectation: 'optional', primary_focuses: ['progression'],
    badge_meta: {
        experience_expectation: { value: 'beginner_friendly', color: '#a855f7' },
        voice_expectation: { value: 'optional', color: null },
        primary_focuses: [{ value: 'progression', color: null }],
        tags: [{ value: 'late-night', label: 'Late night', color: null }],
    },
    permissions: { can_leave: true, can_toggle_notifications: true }, notifications: { enabled: true },
    upcoming_activities: [activity(2, '2026-11-04T20:00:00Z'), activity(1, '2026-11-02T20:00:00Z', { links: { view: '/runs/1', apply: '/runs/1/apply' } })],
    applied_activities: [],
    content_items: [],
    calendar_activities: [activity(3, '2026-11-03T01:00:00Z')],
})

function mount(t, data = group(), cardProps = null, componentPath = null) {
    const ctx = context()
    const props = vue.reactive(cardProps ?? { group: data })
    const root = document.body.appendChild(document.createElement('div'))
    const Page = ctx.load(componentPath ?? (cardProps ? 'components/Runs/RunCard.vue' : 'components/Groups/Dashboard/GroupHomeDashboard.vue')).default
    const app = vue.createApp({ render: () => vue.h(Page, props) })
    app.component('UButton', { props: ['label', 'icon', 'color', 'variant', 'disabled', 'href', 'target', 'rel', 'trailingIcon'],
        setup: (props, { slots, attrs }) => () => vue.h(props.href ? 'a' : 'button', {
            ...attrs, href: props.href, target: props.target, rel: props.rel, disabled: props.disabled,
        }, props.label ?? slots.default?.()),
    })
    app.component('UTooltip', { props: ['text'], setup: (_, { slots }) => () => vue.h('span', slots.default?.()) })
    app.component('UCard', { props: ['ui'], setup: (_, { slots }) => () => vue.h('div', slots.default?.()) })
    app.component('UBadge', { props: ['label', 'color', 'icon', 'variant'], setup: props => () => vue.h('span', { 'data-badge-color': props.color, 'data-badge-icon': props.icon }, props.label) })
    app.component('UIcon', { props: ['name'], setup: props => () => vue.h('i', { 'data-icon': props.name }) })
    app.component('UAvatar', { props: ['src', 'alt', 'icon', 'size', 'ui'], setup: props => () => vue.h('img', { src: props.src, alt: props.alt }) })
    app.component('UAvatarGroup', { props: ['size', 'max'], setup: (_, { slots }) => () => vue.h('div', { 'data-avatar-group': '' }, slots.default?.()) })
    app.component('UCarousel', {
        props: ['items', 'align', 'arrows', 'dragFree', 'watchDrag', 'ui', 'prev', 'next'],
        setup: (props, { slots }) => () => vue.h('div', { 'data-carousel': '' }, props.items.map((item, index) =>
            vue.h('div', { 'data-carousel-slide': '' }, slots.default?.({ item, index })))),
    })
    app.component('USwitch', {
        props: ['modelValue', 'label', 'color', 'size'], emits: ['update:modelValue'],
        setup: (props, { emit }) => () => vue.h('button', {
            role: 'switch', 'aria-checked': String(props.modelValue),
            onClick: () => emit('update:modelValue', !props.modelValue),
        }, props.label),
    })
    app.component('USelectMenu', {
        props: ['modelValue', 'items', 'valueKey', 'searchInput', 'ui', 'color', 'disabled'], emits: ['update:modelValue'],
        setup: (props, { emit }) => () => vue.h('select', {
            value: props.modelValue, disabled: props.disabled, onChange: event => emit('update:modelValue', event.target.value),
        }, props.items.map(item => vue.h('option', { value: item.value }, item.label))),
    })
    app.mount(root)
    t.after(() => { app.unmount(); root.remove() })
    return { ...ctx, root, props,
        button: label => [...root.querySelectorAll('button')].find(button => button.textContent === label || button.getAttribute('aria-label') === label),
    }
}

test('renders the group, notification settings and shared run cards in the selected timezone', async t => {
    const ui = mount(t)
    assert.equal(ui.root.querySelector('h1').textContent, 'Storm Keepers')
    assert.equal(ui.root.querySelectorAll('h1').length, 1)
    assert.equal(ui.root.querySelector('h1').previousElementSibling.textContent, 'Community')
    assert.match(ui.root.textContent, /Late-night progression/)
    ui.button('Report').click()
    assert.deepEqual(ui.reports[0].target, { type: 'group', id: 15, label: 'Storm Keepers' })
    ui.props.group.group_type = 'static'
    await vue.nextTick()
    assert.equal(ui.root.querySelector('h1').previousElementSibling.textContent, 'Static')
    assert.equal(ui.root.querySelector('#group-next-run-title'), null)
    assert.match(ui.root.querySelector('#group-upcoming-runs-title').parentElement.textContent, /Server Time/)
    assert.equal(ui.root.querySelector('img').getAttribute('src'), '/banner.webp')
    const discord = ui.root.querySelector('[aria-label="Open Discord"]')
    assert.equal(discord.getAttribute('href'), 'https://discord.gg/fullparty')
    assert.equal(discord.getAttribute('rel'), 'noopener noreferrer')

    ui.props.view = 'staff'
    await vue.nextTick()
    assert.equal(ui.root.querySelector('[aria-label="Open Settings"]'), null)
    ui.props.group.permissions.can_manage_activities = true
    await vue.nextTick()
    assert.equal(ui.root.querySelector('[aria-label="Open Settings"]').getAttribute('href'), '/en/groups/storm/dashboard/settings')
    ui.props.view = 'user'

    ui.button(translate('groups.notifications.preferences.title')).click()
    await vue.nextTick()
    assert.equal(ui.root.querySelector('[role="dialog"]').textContent, 'Notifications: Storm Keepers')
    const section = ui.root.querySelector('[aria-labelledby="group-upcoming-runs-title"]')
    const card = section.querySelector('a[href="/runs/3"]').closest('article')
    assert.match(card.className, /cursor-pointer/)
    assert.match(card.textContent, /Chaotic Alliance/)
    assert.match(card.textContent, /3 Applicants/)
    assert.doesNotMatch(card.textContent, /Storm Keepers/)
    assert.equal(card.querySelector('img').getAttribute('src'), '/run-banner.webp')
    const dateBadge = card.querySelector('time[aria-label]')
    const fullDate = card.querySelector('time:not([aria-label])')
    assert.equal(dateBadge.getAttribute('title'), 'Server Time')
    assert.match(dateBadge.getAttribute('aria-label'), /Tuesday, 3 November 2026 at 01:00/)
    assert.deepEqual([...dateBadge.children].map(child => child.textContent), ['3', 'Nov'])
    assert.doesNotMatch(card.firstElementChild.textContent, /Scheduled/)
    assert.match(card.lastElementChild.textContent, /Scheduled/)
    assert.match(fullDate.textContent, /Tuesday, 3 November 2026 at 01:00/)
    const details = fullDate.nextElementSibling
    assert.equal(details.children[0].textContent, 'Run Host')
    assert.equal(details.children[1].textContent, '2h')
    assert.equal(details.children[2].textContent, 'Clear')
    assert.equal(details.children[0].querySelector('i').dataset.icon, 'i-lucide-user-round')
    assert.equal(details.children[1].querySelector('i').dataset.icon, 'i-lucide-clock')
    assert.equal(details.children[2].querySelector('i').dataset.icon, 'i-lucide-flag')
    assert.deepEqual([...card.querySelectorAll('[data-avatar-group] img')].map(img => img.getAttribute('src')), ['/applicant-1.webp', null, '/applicant-3.webp'])
    ui.page.props.auth.user.time_display_mode = 'local'
    await vue.nextTick()
    assert.match(fullDate.textContent, /Monday, 2 November 2026 at 17:00/)
    assert.deepEqual([...dateBadge.children].map(child => child.textContent), ['2', 'Nov'])
    assert.match(dateBadge.getAttribute('aria-label'), /America\/Los_Angeles/)
    assert.match(ui.root.querySelector('#group-upcoming-runs-title').parentElement.textContent, /America\/Los_Angeles/)
})

test('default run cards retain the image status and group footer with up to three applicant avatars', t => {
    const ctx = context()
    const run = ctx.load('utils/groupRunCards.ts').groupRunCard(activity(1, '2026-11-05T15:00:00Z'), group())
    run.applicant_avatars.push('/applicant-4.webp')
    const ui = mount(t, group(), { run })
    const card = ui.root.querySelector('article')
    assert.equal(card.querySelector('time[aria-label]'), null)
    assert.match(card.firstElementChild.textContent, /Scheduled/)
    assert.match(card.lastElementChild.textContent, /Storm Keepers/)
    assert.equal(card.querySelector('[data-icon="i-lucide-flag"]'), null)
    assert.doesNotMatch(card.textContent, /Run Host/)
    assert.equal(card.querySelectorAll('[data-avatar-group] img').length, 3)
})

test('date badges follow the selected timezone across a month boundary', async t => {
    const data = group()
    data.calendar_activities = [activity(1, '2026-11-01T00:30:00Z')]
    const ui = mount(t, data)
    ui.now.value = new Date('2026-11-01T00:00:00Z')
    await vue.nextTick()
    const badge = ui.root.querySelector('time[aria-label]')
    assert.deepEqual([...badge.children].map(child => child.textContent), ['1', 'Nov'])
    ui.page.props.auth.user.time_display_mode = 'local'
    await vue.nextTick()
    assert.deepEqual([...badge.children].map(child => child.textContent), ['31', 'Oct'])
})

test('empty groups show the seven-day empty state and hide unavailable membership actions', t => {
    const data = { ...group(), description: null, banner_image_url: null, discord_invite_url: null,
        permissions: { can_leave: false, can_toggle_notifications: false }, upcoming_activities: [], calendar_activities: [],
    }
    const ui = mount(t, data)
    assert.match(ui.root.textContent, /No runs are scheduled in the next 7 days/)
    assert.equal(ui.root.querySelector('select').disabled, true)
    const actions = [...ui.root.querySelectorAll('button, a')]
    assert.ok(!actions.some(action => action.textContent === translate('groups.dashboard.actions.leave_group')))
    assert.ok(!actions.some(action => action.getAttribute('aria-label') === translate('groups.notifications.preferences.title')))
    assert.equal(ui.root.querySelector('#group-applied-runs-title'), null)
    assert.equal(ui.root.querySelector('[role="dialog"]'), null)
})

test('shows every seven-day run in time order and filters only by represented activity types', async t => {
    const data = group()
    const ba = { id: 20, slug: 'ba', draft_name: { en: 'Baldesion Arsenal' } }
    const outside = { id: 30, slug: 'outside', draft_name: { en: 'Outside the seven days' } }
    data.calendar_activities = [
        activity(5, '2026-11-02T23:00:00Z'), activity(2, '2026-11-02T17:00:00Z', { activity_type: ba }),
        activity(4, '2026-11-02T21:00:00Z'), activity(1, '2026-11-02T15:00:00Z', { activity_type: ba }),
        activity(3, '2026-11-02T19:00:00Z'), activity(6, '2026-11-08T23:59:00Z'),
        activity(7, '2026-11-09T00:00:00Z', { activity_type: outside }),
        activity(8, '2026-11-01T23:59:00Z', { activity_type: outside }),
    ]
    const ui = mount(t, data)
    const section = ui.root.querySelector('[aria-labelledby="group-upcoming-runs-title"]')
    const links = () => [...section.querySelectorAll('article')].map(card => card.querySelector('a').getAttribute('href'))
    const select = section.querySelector('select')
    const options = () => [...select.options].map(option => option.textContent)
    assert.deepEqual(links(), ['/runs/1', '/runs/2', '/runs/3', '/runs/4', '/runs/5', '/runs/6'])
    assert.deepEqual(options(), ['All activity types', 'Baldesion Arsenal', 'Chaotic Alliance'])
    assert.equal(section.querySelector('[aria-haspopup="dialog"]'), null)
    select.value = '20'
    select.dispatchEvent(new window.Event('change', { bubbles: true }))
    await vue.nextTick()
    assert.deepEqual(links(), ['/runs/1', '/runs/2'])
    assert.deepEqual(options(), ['All activity types', 'Baldesion Arsenal', 'Chaotic Alliance'])
    select.value = 'all'
    select.dispatchEvent(new window.Event('change', { bubbles: true }))
    await vue.nextTick()
    assert.equal(links().length, 6)
})

test('participation filtering composes with activity types and leaves the personal carousel unchanged', async t => {
    const data = group()
    const statuses = ['pending', 'approved', 'assigned', 'on_bench', 'filled_in', 'checked_in', 'late',
        'missing', 'declined', 'cancelled', 'withdrawn', null]
    data.calendar_activities = statuses.map((participation_status, index) => activity(index + 1, '2026-11-03T18:00:00Z', {
        participation_status,
        activity_type: index === 0 ? { id: 20, slug: 'ba', draft_name: { en: 'Baldesion Arsenal' } } : activity(1).activity_type,
    }))
    data.applied_activities = data.calendar_activities.slice(0, 2)
    const ui = mount(t, data)
    const section = ui.root.querySelector('[aria-labelledby="group-upcoming-runs-title"]')
    const personal = ui.root.querySelector('[aria-labelledby="group-applied-runs-title"]')
    const links = () => [...section.querySelectorAll('article')].map(card => card.querySelector('a').getAttribute('href'))
    const toggle = section.querySelector('[role="switch"]')
    const select = section.querySelector('select')
    const options = [...select.options].map(option => option.textContent)
    assert.equal(toggle.textContent, 'Hide participating')
    assert.equal(toggle.getAttribute('aria-checked'), 'false')
    assert.equal(links().length, 12)
    toggle.click()
    await vue.nextTick()
    assert.equal(toggle.getAttribute('aria-checked'), 'true')
    assert.deepEqual(links(), ['/runs/8', '/runs/9', '/runs/10', '/runs/11', '/runs/12'])
    assert.equal(personal.querySelectorAll('article').length, 2)
    assert.deepEqual([...select.options].map(option => option.textContent), options)

    select.value = '20'
    select.dispatchEvent(new window.Event('change', { bubbles: true }))
    await vue.nextTick()
    assert.equal(links().length, 0)
    assert.match(section.textContent, /No runs match your filters/)
    toggle.click()
    await vue.nextTick()
    assert.deepEqual(links(), ['/runs/1'])
    // Application updates are reflected without resetting either filter.
    toggle.click()
    ui.props.group.calendar_activities[0].participation_status = 'withdrawn'
    await vue.nextTick()
    assert.deepEqual(links(), ['/runs/1'])
})

test('personal run carousels switch between applications and hosting and disappear when empty', async t => {
    const data = group()
    data.applied_activities = [
        activity(9, '2026-11-20T20:00:00Z', { participation_status: 'on_bench' }),
        activity(4, '2026-11-03T20:00:00Z', { participation_status: 'assigned' }),
        activity(5, '2026-11-01T20:00:00Z'), activity(6, null),
    ]
    const ui = mount(t, data)
    const section = ui.root.querySelector('[aria-labelledby="group-applied-runs-title"]')
    assert.equal(section.nextElementSibling.getAttribute('aria-labelledby'), 'group-upcoming-runs-title')
    assert.equal(section.querySelector('h2').textContent, 'Your Upcoming Runs')
    assert.equal(section.querySelector('[data-carousel]').getAttribute('aria-label'), 'Your Upcoming Runs')
    const slides = [...section.querySelectorAll('[data-carousel-slide]')]
    assert.deepEqual(slides.map(slide => slide.querySelectorAll('article').length), [1, 1])
    assert.deepEqual(slides.map(slide => slide.querySelector('a').getAttribute('href')), ['/runs/4', '/runs/9'])
    assert.equal(section.querySelectorAll('time[aria-label]').length, 2)
    assert.match(slides[0].textContent, /Assigned/)
    assert.match(slides[1].textContent, /On Bench/)
    ui.now.value = new Date('2026-11-04T00:00:00Z')
    await vue.nextTick()
    assert.equal(section.querySelectorAll('article').length, 1)
    ui.props.group.applied_activities = []
    await vue.nextTick()
    assert.equal(ui.root.querySelector('#group-applied-runs-title'), null)

    ui.props.view = 'staff'
    await vue.nextTick()
    assert.equal(ui.root.querySelector('#group-hosted-runs-title'), null)
    assert.equal(ui.root.querySelector('#group-run-updates-title'), null)
    ui.props.group.hosted_activities = [activity(20, '2026-11-01T20:00:00Z', { status: 'ongoing' }), activity(21, '2026-11-20T20:00:00Z')]
    ui.props.group.hosted_run_notifications = [{
        id: '1', type: 'applications.new_for_review', category: 'applications', is_unread: true,
        title_key: 'notifications.applications.new_for_review.title', body_key: 'notifications.applications.new_for_review.body',
        message_params: { count: 10, activity: 'Run 21' }, open_url: '/notifications/1/open', created_at: '2026-11-03T12:00:00Z',
    }]
    await vue.nextTick()
    const hosted = ui.root.querySelector('[aria-labelledby="group-hosted-runs-title"]')
    assert.deepEqual([...hosted.querySelectorAll('[data-carousel-slide]')].map(slide => slide.querySelector('a').getAttribute('href')), ['/runs/20', '/runs/21'])
    const updates = ui.root.querySelector('[aria-labelledby="group-run-updates-title"]')
    assert.match(updates.textContent, /10 new applications for Run 21/)
    assert.equal(updates.querySelector('a').getAttribute('href'), '/notifications/1/open')
    ui.props.group.hosted_activities = []
    await vue.nextTick()
    assert.equal(ui.root.querySelector('#group-hosted-runs-title'), null)
    assert.equal(ui.root.querySelector('#group-run-updates-title'), null)
})

test('activity type banners show completed counts and link to type-filtered discovery', async t => {
    const data = group()
    data.content_items = [
        { key: 'type:10', activity_type_id: 10, activity_name: 'The Forked Tower', completed_runs: 12, total_runs: 30,
            activity_banner_image_url: '/tower-banner.webp', activity_image_url: '/tower-small.webp' },
        { key: 'type:20', activity_type_id: 20, activity_name: 'The Baldesion Arsenal', completed_runs: 1, total_runs: 5,
            activity_banner_image_url: null, activity_image_url: '/ba-small.webp' },
        { key: 'name:Legacy', activity_type_id: null, activity_name: 'Legacy', completed_runs: 0, total_runs: 1,
            activity_banner_image_url: null, activity_image_url: null },
    ]
    const ui = mount(t, data)
    const section = ui.root.querySelector('[aria-labelledby="group-activity-types-title"]')
    assert.equal(section.previousElementSibling.getAttribute('aria-labelledby'), 'group-upcoming-runs-title')
    const cards = [...section.querySelectorAll('article')]
    assert.deepEqual(cards.map(card => card.querySelector('h3').textContent), ['The Forked Tower', 'The Baldesion Arsenal', 'Legacy'])
    assert.match(cards[0].textContent, /12 runs done/)
    assert.doesNotMatch(cards[0].textContent, /30/)
    assert.match(cards[1].textContent, /1 run done/)
    assert.match(cards[2].textContent, /0 runs done/)
    assert.equal(cards[0].querySelector('img').getAttribute('src'), '/tower-banner.webp')
    assert.equal(cards[1].querySelector('img').getAttribute('src'), '/ba-small.webp')
    assert.equal(cards[2].querySelector('img'), null)
    assert.equal(cards[2].querySelector('a'), null)
    assert.equal(cards[0].querySelector('a').getAttribute('href'), '/en/dashboard/runs?activity_type_id=10&group=storm')
    assert.equal(cards[0].querySelector('a').getAttribute('aria-label'), 'Find runs for The Forked Tower')
    ui.locale.value = 'fr'
    await vue.nextTick()
    assert.equal(cards[1].querySelector('a').getAttribute('href'), '/fr/dashboard/runs?activity_type_id=20&group=storm')
    ui.props.group.content_items = []
    await vue.nextTick()
    assert.match(section.textContent, /No visible run history yet/)
    assert.equal(section.querySelector('a'), null)
})

test('the shared carousel retains default cards and supports both row counts', async t => {
    const mapper = context().load('utils/groupRunCards.ts').groupRunCard
    const runs = [1, 2, 3].map(id => mapper(activity(id, '2026-11-03T18:00:00Z'), group()))
    const ui = mount(t, group(), { runs, rows: 2, label: 'Home runs' }, 'components/Runs/RunCarousel.vue')
    const counts = () => [...ui.root.querySelectorAll('[data-carousel-slide]')].map(slide => slide.querySelectorAll('article').length)
    assert.deepEqual(counts(), [2, 1])
    assert.equal(ui.root.querySelectorAll('time[aria-label]').length, 0)
    assert.match(ui.root.textContent, /Storm Keepers/)
    ui.props.rows = 1
    ui.props.type = 'date'
    await vue.nextTick()
    assert.deepEqual(counts(), [1, 1, 1])
    assert.equal(ui.root.querySelectorAll('time[aria-label]').length, 3)
})

test('the seven-day list updates its bounds and resets unavailable type selections when timezone or data changes', async t => {
    const data = group()
    const ba = { id: 20, slug: 'ba', draft_name: { en: 'Baldesion Arsenal' } }
    data.calendar_activities = [
        activity(1, '2026-11-02T01:00:00Z', { activity_type: ba }),
        activity(2, '2026-11-03T18:00:00Z'), activity(3, '2026-11-09T01:00:00Z'),
    ]
    const ui = mount(t, data)
    const section = ui.root.querySelector('[aria-labelledby="group-upcoming-runs-title"]')
    const links = () => [...section.querySelectorAll('article')].map(card => card.querySelector('a').getAttribute('href'))
    const select = section.querySelector('select')
    select.value = '20'
    select.dispatchEvent(new window.Event('change', { bubbles: true }))
    await vue.nextTick()
    assert.deepEqual(links(), ['/runs/1'])
    ui.page.props.auth.user.time_display_mode = 'local'
    await vue.nextTick()
    assert.equal(select.value, 'all')
    assert.deepEqual(links(), ['/runs/2', '/runs/3'])
    assert.deepEqual([...select.options].map(option => option.textContent), ['All activity types', 'Chaotic Alliance'])
    select.value = '10'
    select.dispatchEvent(new window.Event('change', { bubbles: true }))
    await vue.nextTick()
    ui.props.group.calendar_activities = []
    await vue.nextTick()
    assert.equal(select.value, 'all')
    assert.equal(select.disabled, true)
    assert.match(section.textContent, /No runs are scheduled in the next 7 days/)
})

test('leaving requires confirmation, cancellation makes no request, and failed requests keep the dialog open', async t => {
    const ui = mount(t)
    ui.button('Leave Group').click()
    await vue.nextTick()
    assert.equal(ui.confirmations.length, 1)
    assert.equal(ui.confirmations[0].title, 'Leave Storm Keepers?')
    assert.equal(ui.calls.length, 0)
    assert.equal(ui.button('Leave Group').disabled, true)
    ui.closeConfirmation(false)
    await Promise.resolve()
    await vue.nextTick()
    assert.equal(ui.calls.length, 0)
    assert.equal(ui.button('Leave Group').disabled, false)

    ui.button('Leave Group').click()
    const patches = []
    ui.setPostResult('error')
    assert.equal(await ui.confirmations[1].onConfirm({ patch: patch => patches.push(patch) }), false)
    assert.deepEqual(patches, [{ confirmLoading: true }, { confirmLoading: false }])
    ui.setPostResult('success')
    assert.equal(await ui.confirmations[1].onConfirm({ patch: () => {} }), true)
    assert.deepEqual(ui.calls.at(-1), { url: 'groups.leave:storm', data: { redirect_to: 'profile' } })
    ui.closeConfirmation(true)
})

test('membership actions enforce permissions and never confirm a different group after navigation', async () => {
    const ctx = context()
    const data = vue.ref(group())
    const actions = ctx.load('composables/useGroupMembershipActions.ts').useGroupMembershipActions(data)
    data.value.permissions.can_leave = false
    data.value.permissions.can_toggle_notifications = false
    await actions.leaveGroup()
    actions.openNotificationPreferences()
    assert.equal(ctx.confirmations.length, 0)
    assert.equal(actions.notificationPreferencesOpen.value, false)

    data.value.permissions.can_leave = true
    const pending = actions.leaveGroup()
    await actions.leaveGroup()
    assert.equal(ctx.confirmations.length, 1)
    data.value = { ...group(), slug: 'other' }
    assert.equal(await ctx.confirmations[0].onConfirm({ patch: () => {} }), false)
    assert.equal(ctx.calls.length, 0)
    ctx.closeConfirmation(false)
    await pending

    data.value.is_visible = false
    const hiddenPending = actions.leaveGroup()
    assert.equal(await ctx.confirmations[1].onConfirm({ patch: () => {} }), true)
    assert.deepEqual(ctx.calls.at(-1), { url: 'groups.leave:other', data: { redirect_to: 'groups' } })
    ctx.closeConfirmation(true)
    await hiddenPending
})

test('the rolling calendar includes timezone boundary runs, sorts days, and handles DST and month boundaries', () => {
    const { groupDashboardDays, nextGroupRun } = context().load('utils/groupDashboardSchedule.ts')
    const now = new Date('2026-11-01T00:30:00Z')
    const runs = [activity(2, '2026-11-07T07:30:00Z'), activity(3, '2026-11-01T01:00:00Z'),
        activity(1, '2026-10-31T23:00:00Z'), activity(4, null), activity(5, 'invalid')]
    const days = groupDashboardDays(runs, now, 'America/Los_Angeles')
    assert.deepEqual(days.map(day => day.key), ['2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'])
    assert.deepEqual(days[0].activities.map(run => run.id), [1, 3])
    assert.deepEqual(days[6].activities.map(run => run.id), [2])
    assert.equal(days.filter(day => day.isToday).length, 1)
    assert.equal(groupDashboardDays(runs, now, 'Pacific/Kiritimati')[0].key, '2026-11-01')
    assert.equal(nextGroupRun(runs, now).id, 3)
    assert.equal(nextGroupRun(runs, new Date('2026-12-01T00:00:00Z')), null)
    assert.deepEqual(runs.map(run => run.id), [2, 3, 1, 4, 5])
})

for (const type of ['default', 'date']) {
    test(`${type} cards replace visibility with the viewer status and shared color, including live changes`, async t => {
        const ctx = context()
        const run = ctx.load('utils/groupRunCards.ts').groupRunCard(activity(1, '2026-11-05T15:00:00Z'), group())
        const ui = mount(t, group(), { run, type })
        const overlay = () => ui.root.querySelector('a').firstElementChild.querySelector('div.absolute')
        for (const [status, label, color] of [
            ['pending', 'Applied', 'warning'], ['approved', 'Approved', 'success'],
            ['assigned', 'Assigned', 'brand'], ['on_bench', 'On Bench', 'info'],
            ['filled_in', 'Fill-in', 'info'], ['checked_in', 'Checked in', 'success'],
            ['late', 'Late', 'warning'], ['missing', 'Missing', 'error'],
            ['declined', 'Declined', 'error'], ['cancelled', 'Cancelled', 'error'],
            ['withdrawn', 'Withdrawn', 'neutral'],
        ]) {
            ui.props.run.participation_status = status
            await vue.nextTick()
            const badge = overlay().lastElementChild
            assert.equal(badge.textContent, label)
            assert.equal(badge.dataset.badgeColor, color)
            assert.match(badge.dataset.badgeIcon, /^i-lucide-/)
            assert.doesNotMatch(overlay().textContent, /Public|Members Only/)
        }
        for (const unknownStatus of [null, 'future_status']) {
            for (const isPublic of [false, true]) {
                ui.props.run.participation_status = unknownStatus
                ui.props.run.is_public = isPublic
                await vue.nextTick()
                const badge = overlay().lastElementChild
                assert.equal(badge.textContent, translate(`groups.dashboard.upcoming_runs.${isPublic ? 'public' : 'private'}`))
                assert.equal(badge.dataset.badgeColor, 'neutral')
            }
        }
    })
}

test('group dashboards forward viewer participation to date cards', t => {
    const data = group()
    data.calendar_activities[0].participation_status = 'assigned'
    const ui = mount(t, data)
    const card = ui.root.querySelector('a[href="/runs/3"]').closest('article')
    assert.equal(card.querySelector('[data-badge-color="brand"]').textContent, 'Assigned')
    assert.doesNotMatch(card.firstElementChild.textContent, /Public/)
})

test('every shared participation status has a label in every supported locale', () => {
    const statuses = Object.keys(context().load('utils/runParticipationMeta.ts').RUN_PARTICIPATION_META)
    for (const locale of ['en', 'de', 'fr', 'ja']) {
        const labels = JSON.parse(readFileSync(new URL(`../../lang/${locale}/runs.json`, import.meta.url), 'utf8')).participation.statuses
        for (const status of statuses) assert.ok(labels[status]?.trim(), `${locale}: ${status}`)
    }
})


test('date cards localize target progress and handle an unset host or target', async t => {
    const ctx = context()
    const run = ctx.load('utils/groupRunCards.ts').groupRunCard(activity(1, '2026-11-05T15:00:00Z'), group())
    const ui = mount(t, group(), { run, type: 'date' })
    const details = ui.root.querySelector('time:not([aria-label])').nextElementSibling
    ui.locale.value = 'fr'
    await vue.nextTick()
    assert.equal(details.lastElementChild.textContent, 'Victoire')
    ui.props.run.host_name = null
    ui.props.run.target_prog_point_label = null
    await vue.nextTick()
    assert.equal(details.firstElementChild.textContent, translate('groups.activities.cards.no_organizer'))
    assert.equal(details.lastElementChild.textContent, 'clear')
    ui.props.run.target_prog_point_key = null
    ui.props.run.duration_hours = null
    await vue.nextTick()
    assert.equal(details.querySelector('[data-icon="i-lucide-flag"]'), null)
    assert.equal(details.querySelector('[data-icon="i-lucide-clock"]'), null)
})

test('date cards hide duration before shortening progress and restore it when the row has room', async t => {
    const run = context().load('utils/groupRunCards.ts').groupRunCard(activity(1, '2026-11-05T15:00:00Z'), group())
    const ui = mount(t, group(), { run, type: 'date' })
    const row = ui.root.querySelector('time:not([aria-label])').nextElementSibling
    const [host, duration, progress] = row.children
    let rowWidth = 400
    let progressWidth = 120
    // jsdom has no layout engine; supply text and container metrics to the real component.
    row.style.columnGap = '12px'
    for (const item of [host, progress]) {
        item.style.columnGap = '6px'
        item.firstElementChild.style.width = '14px'
    }
    Object.defineProperty(row, 'clientWidth', { get: () => rowWidth })
    Object.defineProperty(host.lastElementChild, 'scrollWidth', { get: () => 64 })
    Object.defineProperty(progress.lastElementChild, 'scrollWidth', { get: () => progressWidth })
    Object.defineProperty(duration, 'offsetWidth', { get: () => 40 })
    ui.resize()
    await vue.nextTick()
    assert.equal(duration.hasAttribute('aria-hidden'), false)

    rowWidth = 330
    ui.resize()
    await vue.nextTick()
    assert.equal(duration.getAttribute('aria-hidden'), 'true')
    assert.ok(duration.classList.contains('absolute'))
    assert.equal(progress.getAttribute('title'), 'Clear')

    rowWidth = 180
    ui.resize()
    await vue.nextTick()
    assert.equal(duration.getAttribute('aria-hidden'), 'true')
    assert.ok(progress.lastElementChild.classList.contains('truncate'))
    assert.equal(progress.lastElementChild.textContent, 'Clear')

    rowWidth = 400
    ui.resize()
    await vue.nextTick()
    assert.equal(duration.hasAttribute('aria-hidden'), false)
    // Longer translated/updated text must hide duration even without a container resize.
    progressWidth = 240
    ui.props.run.target_prog_point_label = { en: 'A much longer progress point' }
    await vue.nextTick()
    assert.equal(duration.getAttribute('aria-hidden'), 'true')
    assert.equal(progress.getAttribute('title'), 'A much longer progress point')
})


test('default cards link the group separately only when its dashboard is accessible', async t => {
    const run = context().load('utils/groupRunCards.ts').groupRunCard(activity(1, '2026-11-05T15:00:00Z'), group())
    run.group.dashboard_href = '/en/groups/storm'
    const ui = mount(t, group(), { run, carousel: true })
    const groupLink = ui.root.querySelector('a[href="/en/groups/storm"]')
    assert.equal(groupLink.textContent, 'Storm Keepers')
    assert.equal(groupLink.querySelector('img').getAttribute('src'), '/group-avatar.webp')
    assert.equal(groupLink.getAttribute('draggable'), 'false')
    assert.equal(ui.root.querySelector('a a'), null)
    const destinations = []
    ui.root.addEventListener('click', event => {
        destinations.push(event.target.closest('a')?.getAttribute('href'))
        event.preventDefault()
    })
    groupLink.querySelector('img').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }))
    ui.root.querySelector('a[href="/runs/1"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }))
    assert.deepEqual(destinations, ['/en/groups/storm', '/runs/1'])

    ui.props.run.group.dashboard_href = null
    await vue.nextTick()
    assert.equal(ui.root.querySelector('a[href="/en/groups/storm"]'), null)
    assert.match(ui.root.textContent, /Storm Keepers/)
    assert.ok([...ui.root.querySelectorAll('a')].every(link => link.getAttribute('href') === '/runs/1'))

    ui.props.run.group.dashboard_href = '/en/groups/storm'
    ui.props.type = 'date'
    await vue.nextTick()
    assert.equal(ui.root.querySelector('a[href="/en/groups/storm"]'), null)
    assert.equal(ui.root.querySelector('a a'), null)
})
