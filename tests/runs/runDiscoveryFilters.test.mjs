import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import * as calendar from '@internationalized/date'
import ts from 'typescript'

const dom = new JSDOM('<html><body></body></html>', { url: 'https://fullparty.test/en/runs' })
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node']) globalThis[key] = dom.window[key]
const vue = await import('vue')
function load(file, modules) {
    const code = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const exports = {}
    new Function('require', 'exports', code)(name => {
        assert.ok(name in modules, name)
        return modules[name]
    }, exports)
    return exports
}
const search = load('../../resources/js/utils/runBrowseSearch.ts', {
    '@internationalized/date': calendar,
    '../Types/RunDiscovery': load('../../resources/js/Types/RunDiscovery.ts', {}),
})
const lookups = {
    activity_types: [{ id: 10, value: 'forked-tower' }, { id: 20, value: 'savage' }],
    groups: [{ value: 'storm' }, { value: 'static-team' }],
    regions: [{ value: 'Europe' }, { value: 'Japan' }],
    datacenters: [{ value: 'Light', region: 'Europe' }],
    languages: [{ value: 'EN' }],
    intensities: ['casual'],
    run_styles: ['reclear'],
}

test('search accepts group activity links and ignores unavailable or malformed filters', () => {
    for (const [query, activity, group = ''] of [
        ['activity_type_id=10&activity_type=savage&group=storm', 'forked-tower', 'storm'],
        ['activity_type=savage&group=static-team', 'savage', 'static-team'],
        ['activity_type_id=999&group=unavailable', ''],
        ['activity_type_id=20junk', ''],
        ['activity_type_id[]=20', ''],
        ['', ''],
    ]) {
        const state = search.readRunSearch(new URLSearchParams(query), lookups)
        assert.equal(state.activity, activity)
        assert.equal(state.group, group)
    }
    const invalid = search.readRunSearch(new URLSearchParams('date_from=2026-02-30&date_to=2026-03-01&date_flex_days=50&flexible_days=holiday&date_months[]=2026-13'), lookups)
    assert.deepEqual(invalid.dates, search.emptyRunSearch().dates)
    const unknown = search.readRunSearch(new URLSearchParams('region=Japan&datacenter=Light&language=XX&run_style=invalid&application_status=invalid&sort=invalid'), lookups)
    assert.deepEqual(unknown.filters, { ...search.emptyRunSearchFilters(), region: 'Japan' })
    assert.equal(unknown.sort, 'starting_soonest')
})

test('date ranges and flexible days survive shareable URL round trips and ignore legacy month selections', () => {
    for (const dates of [
        { mode: 'dates', from: '2026-11-20', to: '2026-11-24', tolerance: 3, days: 'any' },
        { mode: 'dates', from: '2026-11-20', to: null, tolerance: 0, days: 'any' },
        { mode: 'flexible', from: null, to: null, tolerance: 0, days: 'weekend' },
    ]) {
        const filters = {
            saved_only: true, beginner_friendly: true, role_category: 'healer', application_status: 'applications_open',
            time_of_day: 'evening', region: 'Europe', datacenter: 'Light', language: 'EN', run_style: 'reclear', intensity: 'casual',
        }
        const state = vue.reactive({ activity: 'forked-tower', group: 'storm', dates, filters, sort: 'newest_posted' })
        const params = search.runSearchParams(search.copyRunSearch(state), 'America/Los_Angeles', 2)
        assert.equal(params.get('timezone'), 'America/Los_Angeles')
        assert.equal(params.get('page'), '2')
        assert.deepEqual(search.readRunSearch(params, lookups), state)
        assert.equal(search.activeRunFilterCount(state.filters), Object.keys(filters).length)
    }
    const oldLink = new URLSearchParams('date_range=flexible&flexible_days=weekday&date_months[]=2026-11')
    const restored = search.readRunSearch(oldLink, lookups)
    const submitted = search.runSearchParams(restored, 'UTC')
    assert.equal(restored.sort, 'starting_soonest')
    assert.equal(submitted.has('sort'), false)
    assert.equal(submitted.get('flexible_days'), 'weekday')
    assert.equal(submitted.has('date_months[]'), false)
})

test('the topbar opens Find Runs from another page and reuses its options for subsequent searches', async t => {
    const page = vue.reactive({
        component: 'Dashboard/Groups/Settings',
        url: '/en/groups/storm/settings',
        props: { auth: { user: { id: 1 } }, locale: { current: 'en' } },
    })
    const visits = []
    const history = []
    let requests = 0
    const { useGlobalRunSearch } = load('../../resources/js/composables/useGlobalRunSearch.ts', {
        vue,
        '@inertiajs/vue3': {
            usePage: () => page,
            router: { visit: url => visits.push(url), push: ({ url }) => history.push(url) },
        },
        axios: { default: { get: async () => { requests++; return { data: lookups } } } },
        'ziggy-js': { route: () => '/en/runs' },
        '@/composables/useDateTimeFormat': { useDateTimeFormat: () => ({ displayTimeZone: vue.ref('UTC') }) },
        '@/utils/runBrowseSearch': search,
    })
    let header
    const root = document.body.appendChild(document.createElement('div'))
    const app = vue.createApp({ setup() { header = useGlobalRunSearch(); return () => null } })
    app.mount(root)
    t.after(() => { app.unmount(); root.remove() })

    assert.equal(requests, 0)
    assert.equal(header.appliedSearch.value, null)
    await Promise.all([header.loadOptions(), header.loadOptions()])
    assert.equal(requests, 1)
    header.draft.value.group = 'storm'
    header.submit()
    assert.equal(new URL(visits[0], window.location.origin).searchParams.get('group'), 'storm')
    page.props.lookups = lookups
    page.component = 'Dashboard/Runs/Index'
    page.url = visits[0]
    await vue.nextTick()
    await header.loadOptions()
    assert.equal(requests, 1)
    assert.equal(header.draft.value.group, 'storm')
    header.draft.value.activity = 'savage'
    assert.equal(header.appliedSearch.value.activity, '')
    page.url = page.url + '&region=Europe&sort=least_full'
    await vue.nextTick()
    header.draft.value = { ...search.emptyRunSearch(), activity: 'savage' }
    header.submit()
    const params = new URL(history.at(-1), window.location.origin).searchParams
    assert.equal(visits.length, 1)
    assert.equal(params.get('activity_type'), 'savage')
    assert.equal(params.get('region'), 'Europe')
    assert.equal(params.get('sort'), 'least_full')
    header.backToBrowse()
    page.url = history.at(-1)
    await vue.nextTick()
    assert.equal(header.appliedSearch.value, null)
    assert.deepEqual(header.draft.value, search.emptyRunSearch())
    page.url = '/en/runs?search=1'
    await vue.nextTick()
    assert.deepEqual(header.appliedSearch.value, search.emptyRunSearch())
})

test('search restores filters and adapts pagination to grid changes independently of the display timezone', async t => {
    const sharedUrl = '/en/runs?activity_type_id=10&group=storm&timezone=Asia%2FTokyo&sort=least_full'
    const page = vue.reactive({ url: sharedUrl })
    const timezone = vue.ref('America/Los_Angeles')
    const pageSize = vue.ref(9)
    const requests = []
    const replacedUrls = []
    const route = () => '/en/runs'
    const navigate = ({ url }) => { page.url = url }
    const { useRunBrowseSearch } = load('../../resources/js/composables/useRunBrowseSearch.ts', {
        vue,
        '@inertiajs/vue3': { usePage: () => page, router: {
            push: navigate,
            replace: visit => { replacedUrls.push(visit.url); navigate(visit) },
        } },
        axios: { default: { get: (url, config) => new Promise(resolve => requests.push({ ...config, resolve })) } },
        'ziggy-js': { route },
        '@/composables/useDateTimeFormat': { useDateTimeFormat: () => ({ displayTimeZone: timezone }) },
        '@/utils/runBrowseSearch': search,
    })
    let state
    const root = document.body.appendChild(document.createElement('div'))
    const app = vue.createApp({ setup() { state = useRunBrowseSearch(() => lookups, () => pageSize.value); return () => null } })
    app.mount(root)
    t.after(() => { app.unmount(); root.remove() })
    assert.equal(requests[0].params.get('activity_type'), 'forked-tower')
    assert.equal(requests[0].params.get('group'), 'storm')
    assert.equal(requests[0].params.get('timezone'), 'Asia/Tokyo')
    assert.equal(requests[0].params.get('per_page'), '9')

    state.selectActivity('savage')
    await vue.nextTick()
    assert.equal(requests[0].signal.aborted, true)
    assert.equal(requests[1].params.get('activity_type'), 'savage')
    assert.equal(requests[1].params.get('timezone'), 'America/Los_Angeles')
    requests[1].resolve({ data: { cards: [{ id: 2 }] } })
    await vue.nextTick()
    requests[0].resolve({ data: { cards: [{ id: 1 }] } })
    await vue.nextTick()
    assert.equal(state.result.value.cards[0].id, 2)

    const currentUrl = page.url
    const loadedResults = state.result.value
    timezone.value = 'UTC'
    await vue.nextTick()
    assert.equal(page.url, currentUrl)
    assert.equal(requests.length, 2)
    assert.equal(state.loading.value, false)
    assert.equal(state.result.value, loadedResults)
    // Inertia restores page.url on browser Back; the search must restore its filters too.
    page.url = sharedUrl
    await vue.nextTick()
    assert.equal(requests.at(-1).params.get('activity_type'), 'forked-tower')
    assert.equal(requests.at(-1).params.get('group'), 'storm')
    state.goToPage(3)
    await vue.nextTick()
    assert.equal(requests.at(-1).params.get('timezone'), 'Asia/Tokyo')
    assert.equal(requests.at(-1).params.get('sort'), 'least_full')
    state.applyFilters({ ...state.applied.value.filters, role_category: 'tank', region: 'Europe', beginner_friendly: true })
    await vue.nextTick()
    const filtered = requests.at(-1).params
    assert.equal(filtered.get('page'), '1')
    assert.equal(filtered.get('activity_type'), 'forked-tower')
    assert.equal(filtered.get('group'), 'storm')
    assert.equal(filtered.get('beginner_friendly'), '1')
    assert.equal(filtered.get('timezone'), 'Asia/Tokyo')
    state.goToPage(2)
    await vue.nextTick()
    state.setSort('most_full')
    await vue.nextTick()
    assert.deepEqual(Object.fromEntries(requests.at(-1).params), { ...Object.fromEntries(filtered), page: '1', sort: 'most_full' })
    state.goToPage(2)
    await vue.nextTick()
    assert.equal(requests.at(-1).params.get('sort'), 'most_full')
    page.url = '/en/runs?role_category=tank'
    await vue.nextTick()
    assert.equal(state.active.value, true)
    state.goToPage(5)
    await vue.nextTick()
    assert.equal(requests.at(-1).params.get('page'), '5')
    assert.equal(requests.at(-1).params.get('role_category'), 'tank')
    const paginatedUrl = page.url
    const inFlight = requests.at(-1)
    const requestCount = requests.length
    timezone.value = 'America/Los_Angeles'
    await vue.nextTick()
    assert.equal(page.url, paginatedUrl)
    assert.equal(requests.length, requestCount)
    assert.equal(inFlight.signal.aborted, false)
    state.retry()
    assert.equal(requests.at(-1).params.get('page'), '5')
    assert.equal(requests.at(-1).params.get('timezone'), 'UTC')
    requests.at(-1).resolve({ data: { cards: [{ id: 37 }], meta: { current_page: 5, per_page: 9, total: 90, last_page: 10 } } })
    await vue.nextTick()
    const beforeResize = requests.length
    pageSize.value = 12
    await vue.nextTick()
    // Run 37, previously first on page five, now belongs to page four.
    assert.deepEqual(replacedUrls, [page.url])
    assert.equal(requests.length, beforeResize + 1)
    assert.equal(requests.at(-1).params.get('page'), '4')
    assert.equal(requests.at(-1).params.get('per_page'), '12')
    assert.equal(requests.at(-1).params.get('role_category'), 'tank')
    assert.equal(requests.at(-1).params.get('timezone'), 'UTC')

    pageSize.value = 10
    await vue.nextTick()
    assert.equal(requests.length, beforeResize + 2)
    assert.equal(requests.at(-1).params.get('per_page'), '10')
    assert.equal(requests.at(-1).params.get('page'), '4')
    state.clear()
    await vue.nextTick()
    assert.equal(requests.at(-1).signal.aborted, true)
    assert.equal(state.active.value, false)
    assert.equal(state.result.value, null)
    pageSize.value = 9
    await vue.nextTick()
    assert.equal(requests.length, beforeResize + 2)
})
