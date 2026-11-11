import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { computed, reactive } from 'vue'

const code = ts.transpileModule(readFileSync(new URL('../../resources/js/composables/useGroupDashboardNavigation.ts', import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText
const homeCode = ts.transpileModule(readFileSync(new URL('../../resources/js/composables/useGroupHomeNavigation.ts', import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText

function setup(overrides = {}) {
    const group = reactive({ slug: 'ftel', permissions: {}, features: {}, ...overrides })
    const page = reactive({ url: '/en/groups/ftel/dashboard/activities', props: { locale: { current: 'en' } } })
    const paths = {
        'activities.index': 'activities', 'activities.create': 'activities/create', 'content.index': 'content',
        'resources.index': 'resources', 'resources.manage': 'content/resources',
        'membership-applications.index': 'membership-applications',
        'membership-application-form.edit': 'membership-application-form',
    }
    const modules = {
        vue: { computed }, '@inertiajs/vue3': { usePage: () => page },
        'vue-i18n': { useI18n: () => ({ t: key => key.split('.').at(-1) }) },
        'ziggy-js': { route: (name, params) => {
            const suffix = name.replace(/^groups\.dashboard\.?/, '')
            const query = new URLSearchParams({ ...(params.type ? { type: params.type } : {}), ...params._query }).toString()
            return `/${params.locale}/groups/${params.group}/dashboard${suffix ? `/${paths[suffix] ?? suffix.replaceAll('.', '/')}` : ''}${query ? `?${query}` : ''}`
        } },
    }
    const exports = {}
    new Function('require', 'exports', code)(id => {
        assert.ok(id in modules, id)
        return modules[id]
    }, exports)
    const { sections } = exports.useGroupDashboardNavigation(() => group)
    modules['@/composables/useGroupDashboardNavigation'] = exports
    const homeExports = {}
    new Function('require', 'exports', homeCode)(id => {
        assert.ok(id in modules, id)
        return modules[id]
    }, homeExports)
    const { navigation, quickLinkSections } = homeExports.useGroupHomeNavigation(() => group)
    const homeLinks = () => navigation.value.flatMap(item => item.links ?? [item])
    const quickLinks = () => quickLinkSections.value.flatMap(section => section.links)
    const labels = () => sections.value.flatMap(section => section.links.map(link => link.label))
    const active = () => sections.value.flatMap(section => section.links).filter(link => link.active).map(link => link.label)
    return { group, page, sections, labels, active, navigation, quickLinkSections, homeLinks, quickLinks }
}

test('visitors only see the public group destinations, even when optional features are enabled', () => {
    const nav = setup({ features: { resource_hub_enabled: true, availability_scheduler_enabled: true } })
    assert.deepEqual(nav.labels(), ['home', 'activities'])
    assert.deepEqual(nav.homeLinks().map(link => link.key), ['home', 'activities'])
    assert.deepEqual(nav.quickLinks().map(link => link.key), ['activities'])
})

test('members retain enabled features without management controls or moderator-only availability', () => {
    const nav = setup({ current_user_role: 'member', permissions: { can_view_members: true }, features: {
        resource_hub_enabled: true, availability_scheduler_enabled: true, availability_minimum_role: 'moderator',
    } })
    assert.deepEqual(nav.labels(), ['home', 'activities', 'members', 'resources', 'statistics', 'leaderboard', 'legacy_leaderboard'])
    assert.ok(!nav.navigation.value.some(item => item.key === 'manage'))
    assert.ok(!nav.quickLinkSections.value.some(section => section.key === 'staff_workspace'))
    assert.ok(!nav.quickLinks().some(link => link.key === 'create_run'))
    nav.group.features.availability_minimum_role = 'member'
    assert.ok(nav.labels().includes('availability'))
    assert.equal(nav.homeLinks().find(link => link.key === 'availability').badge, undefined)
    nav.group.features.statistics_enabled = false
    nav.group.features.leaderboard_enabled = false
    nav.group.features.resource_hub_enabled = false
    assert.deepEqual(nav.labels(), ['home', 'activities', 'members', 'availability'])
    assert.deepEqual(nav.navigation.value.map(item => item.key), ['home', 'planning', 'members'])
    assert.deepEqual(nav.quickLinks().map(link => link.key), ['activities'])
})

test('management permissions control each section independently', () => {
    const nav = setup({ current_user_role: 'moderator', permissions: { can_view_members: true, can_manage_members: true }, features: {
        resource_hub_enabled: true, availability_scheduler_enabled: true, availability_minimum_role: 'moderator',
    } })
    assert.ok(nav.labels().includes('manage_resources'))
    assert.ok(nav.labels().includes('delubrum_reginae_savage'))
    assert.ok(nav.labels().includes('forked_tower_blood'))
    assert.ok(nav.labels().includes('availability'))
    assert.ok(!nav.sections.value.some(section => ['moderation', 'configuration'].includes(section.key)))
    assert.equal(nav.homeLinks().find(link => link.key === 'availability').badge, 'staff_only')
    assert.deepEqual(nav.navigation.value.find(item => item.key === 'manage').links.map(link => link.key), [
        'manage_resources', 'content_customization', 'audit_log', 'settings',
    ])
    assert.equal(nav.quickLinks().find(link => link.key === 'content_customization').href, '/en/groups/ftel/dashboard/content')
    assert.equal(nav.quickLinks().find(link => link.key === 'audit_log').href, '/en/groups/ftel/dashboard/settings?section=audit_log')
    assert.ok(!nav.quickLinks().some(link => link.key === 'create_run'))
    nav.group.permissions.can_manage_activities = true
    assert.equal(nav.quickLinks().find(link => link.key === 'create_run').href, '/en/groups/ftel/dashboard/activities/create')
    nav.group.permissions.can_manage_discovery = true
    nav.group.permissions.can_manage_group = true
    nav.group.permissions.can_review_membership_applications = true
    nav.group.permissions.can_manage_membership_application_form = true
    assert.ok(nav.labels().includes('membership_applications'))
    assert.ok(nav.labels().includes('application_form'))
    assert.ok(nav.homeLinks().some(link => link.key === 'membership_applications'))
    assert.ok(nav.homeLinks().some(link => link.key === 'application_form'))
})

test('active links follow query strings, nested runs, group switches and locale changes', () => {
    const nav = setup({ current_user_role: 'owner', permissions: { can_manage_group: true, can_manage_members: true }, features: { resource_hub_enabled: true } })
    nav.page.url = '/en/groups/ftel/dashboard?tab=overview'
    assert.deepEqual(nav.active(), ['home'])
    nav.page.url = '/en/groups/ftel/dashboard/activities/123?tab=roster'
    assert.deepEqual(nav.active(), ['activities'])
    nav.page.url = '/en/groups/ftel/dashboard/content/resources?resource=123'
    assert.deepEqual(nav.active(), ['manage_resources'])
    assert.deepEqual(nav.homeLinks().filter(link => link.active).map(link => link.key), ['manage_resources'])
    nav.page.url = '/en/groups/ftel/dashboard/content/resources/123/edit'
    assert.deepEqual(nav.homeLinks().filter(link => link.active).map(link => link.key), ['manage_resources'])
    nav.page.url = '/en/groups/ftel/dashboard/content'
    assert.deepEqual(nav.homeLinks().filter(link => link.active).map(link => link.key), ['content_customization'])
    nav.page.url = '/en/groups/ftel/dashboard/content?type=drs'
    assert.deepEqual(nav.active(), ['delubrum_reginae_savage'])
    assert.deepEqual(nav.homeLinks().filter(link => link.active).map(link => link.key), ['content_customization'])
    nav.page.url = '/en/groups/ftel/dashboard/content?type=forkedtower'
    assert.deepEqual(nav.active(), ['forked_tower_blood'])
    assert.equal(nav.sections.value.flatMap(section => section.links).find(link => link.key === 'forked_tower_blood').href, nav.page.url)
    assert.deepEqual(nav.homeLinks().filter(link => link.active).map(link => link.key), ['content_customization'])
    nav.page.url = '/en/groups/ftel/dashboard/settings?section=audit_log'
    assert.ok(nav.homeLinks().find(link => link.key === 'audit_log').active)
    assert.ok(nav.navigation.value.find(item => item.key === 'manage').active)
    nav.group.slug = 'another'
    nav.page.props.locale.current = 'de'
    nav.page.url = '/de/groups/another/dashboard/activities?status=scheduled'
    assert.deepEqual(nav.active(), ['activities'])
    assert.ok(!nav.labels().includes('legacy_leaderboard'))
    assert.ok(nav.sections.value.flatMap(section => section.links).every(link => link.href.startsWith('/de/groups/another/')))
    assert.ok(nav.navigation.value.find(item => item.key === 'planning').active)
    assert.ok([...nav.homeLinks(), ...nav.quickLinks()].every(link => link.href.startsWith('/de/groups/another/')))
    assert.equal(nav.quickLinks().find(link => link.key === 'audit_log').href, '/de/groups/another/dashboard/settings?section=audit_log')
})
