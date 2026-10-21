import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue'

function load(file, modules = {}) {
    const source = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const exports = {}
    new Function('require', 'exports', source)(id => {
        assert.ok(id in modules, id)
        return modules[id]
    }, exports)
    return exports
}

function setup(t, { zone = 'Europe/London', mode = 'local' } = {}) {
    const previousZone = process.env.TZ
    process.env.TZ = zone
    const scope = effectScope()
    t.after(() => {
        scope.stop()
        if (previousZone === undefined) delete process.env.TZ
        else process.env.TZ = previousZone
    })
    const locale = ref('en')
    const page = reactive({ props: { auth: { user: { time_display_mode: mode } } } })
    const preference = load('../../resources/js/composables/useTimeDisplayMode.ts', {
        vue: { computed, ref, watch },
        '@inertiajs/vue3': { usePage: () => page },
    })
    const { useDateTimeFormat } = load('../../resources/js/composables/useDateTimeFormat.ts', {
        vue: { computed },
        'vue-i18n': { useI18n: () => ({ locale }) },
        '@/composables/useTimeDisplayMode': preference,
        '@/utils/dateTimeFormat': load('../../resources/js/utils/dateTimeFormat.ts'),
    })
    return {
        page, locale, preference,
        formatter: () => scope.run(useDateTimeFormat),
        mode: () => scope.run(preference.useTimeDisplayMode),
        render: fn => scope.run(() => computed(fn)),
    }
}

const clock = { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }
const date = { year: 'numeric', month: '2-digit', day: '2-digit', ...clock }

test('all mounted formatters and newly loaded content react to LT/ST changes', async t => {
    const state = setup(t)
    const first = state.formatter()
    const second = state.formatter()
    const stamp = '2026-10-21T20:00:00Z'
    const cards = state.render(() => [first, second].map(api => api.formatDateTime(stamp, clock)))
    assert.deepEqual(cards.value, ['21:00', '21:00'])

    state.preference.setSharedTimeDisplayMode('server')
    assert.deepEqual(cards.value, ['20:00', '20:00'])
    assert.equal(state.formatter().formatDateTime(stamp, clock), '20:00')
    assert.equal(first.timeDisplayLabel.value, 'ST')
    assert.equal(first.displayTimeZoneLabel.value, 'UTC')

    state.page.props.auth.user.time_display_mode = 'server'
    await nextTick()
    state.page.props.auth.user.time_display_mode = 'local'
    await nextTick()
    assert.deepEqual(cards.value, ['21:00', '21:00'])
    assert.equal(first.timeDisplayLabel.value, 'LT')
})

test('local offsets are calculated for each run date, including both DST transitions', t => {
    const state = setup(t)
    const api = state.formatter()
    const stamps = ['2026-03-28T20:00:00Z', '2026-03-29T20:00:00Z', '2026-10-24T20:00:00Z', '2026-10-25T20:00:00Z']
    assert.deepEqual(stamps.map(stamp => api.formatDateTime(stamp, clock)), ['20:00', '21:00', '21:00', '20:00'])
    state.preference.setSharedTimeDisplayMode('server')
    assert.deepEqual(stamps.map(stamp => api.formatDateTime(stamp, clock)), ['20:00', '20:00', '20:00', '20:00'])
})

test('the date, clock, timezone abbreviation and calendar parts change together across midnight', t => {
    const state = setup(t)
    const api = state.formatter()
    const stamp = '2026-10-21T23:30:00Z'
    const parts = state.render(() => Object.fromEntries(api.formatDateTimeParts(stamp, { ...date, timeZoneName: 'short' }).map(p => [p.type, p.value])))
    assert.equal(api.formatDateTime(stamp, date), '22/10/2026, 00:30')
    assert.equal(parts.value.day, '22')
    assert.equal(parts.value.hour, '00')
    assert.equal(parts.value.timeZoneName, 'BST')
    state.preference.setSharedTimeDisplayMode('server')
    assert.equal(api.formatDateTime(stamp, date), '21/10/2026, 23:30')
    assert.equal(parts.value.day, '21')
    assert.equal(parts.value.hour, '23')
    assert.equal(parts.value.timeZoneName, 'UTC')
})

test('negative local offsets can put a run on the previous calendar day', t => {
    const state = setup(t, { zone: 'America/Los_Angeles' })
    const api = state.formatter()
    const stamp = '2026-10-21T02:00:00Z'
    assert.equal(api.formatDateTime(stamp, date), '20/10/2026, 19:00')
    state.preference.setSharedTimeDisplayMode('server')
    assert.equal(api.formatDateTime(stamp, date), '21/10/2026, 02:00')
})

test('explicit offsets identify the same instant and locale changes update mounted labels', t => {
    const state = setup(t, { mode: 'server' })
    const api = state.formatter()
    assert.equal(api.formatDateTime('2026-10-21T21:00:00+01:00', date), api.formatDateTime('2026-10-21T20:00:00Z', date))
    const label = state.render(() => api.formatDateTime('2026-10-21T20:00:00Z', { dateStyle: 'full' }))
    for (const language of ['en', 'de', 'fr', 'ja']) {
        state.locale.value = language
        const expected = new Intl.DateTimeFormat(language === 'en' ? 'en-GB' : language, { timeZone: 'UTC', dateStyle: 'full' }).format(new Date('2026-10-21T20:00:00Z'))
        assert.equal(label.value, expected)
    }
})

test('the selected display mode takes precedence over a stale timezone option', t => {
    const state = setup(t)
    const mode = state.mode()
    assert.equal(mode.withDisplayTimeZone({ timeZone: 'UTC' }).timeZone, undefined)
    mode.setTimeDisplayMode('server')
    assert.equal(mode.withDisplayTimeZone({ timeZone: 'America/New_York' }).timeZone, 'UTC')
})

test('missing and invalid timestamps have safe fallbacks without dropping the Unix epoch', t => {
    const state = setup(t, { mode: 'server' })
    const api = state.formatter()
    for (const value of [null, undefined, '', 'invalid', new Date(NaN), NaN]) {
        assert.equal(api.formatDateTime(value), '—')
        assert.equal(api.formatDateTime(value, clock, 'No time'), 'No time')
        assert.deepEqual(api.formatDateTimeParts(value), [])
    }
    assert.equal(api.formatDateTime(0, date), '01/01/1970, 00:00')
})
