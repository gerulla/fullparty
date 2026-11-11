import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { computed, effectScope, nextTick, reactive, ref, toValue, watch } from 'vue'
import * as calendar from '@internationalized/date'

const navigationMessages = Object.fromEntries(['en', 'de', 'fr', 'ja'].map(locale => [locale,
    JSON.parse(readFileSync(new URL(`../../lang/${locale}/navigation.json`, import.meta.url), 'utf8')),
]))

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

const dateTime = load('../../resources/js/utils/dateTimeFormat.ts', { '@internationalized/date': calendar })
const scheduleTime = load('../../resources/js/utils/availabilityScheduleTime.ts', {
    '@internationalized/date': calendar, './dateTimeFormat': dateTime,
})

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
        'vue-i18n': { useI18n: () => ({ t: key => key.split('.').slice(1).reduce((entry, part) => entry[part], navigationMessages[locale.value]) }) },
    })
    const { useDateTimeFormat } = load('../../resources/js/composables/useDateTimeFormat.ts', {
        vue: { computed },
        'vue-i18n': { useI18n: () => ({ locale }) },
        '@/composables/useTimeDisplayMode': preference,
        '@/utils/dateTimeFormat': dateTime,
    })
    const schedule = now => load('../../resources/js/composables/useAvailabilityScheduleTime.ts', {
        vue: { computed, toValue },
        '@vueuse/core': { useNow: () => now },
        '@internationalized/date': calendar,
        'vue-i18n': { useI18n: () => ({ t: key => key }) },
        '@/composables/useDateTimeFormat': { useDateTimeFormat },
        '@/utils/dateTimeFormat': dateTime,
        '@/utils/availabilityScheduleTime': scheduleTime,
    }).useAvailabilityScheduleTime
    return {
        page, locale, preference,
        formatter: () => scope.run(useDateTimeFormat),
        mode: () => scope.run(preference.useTimeDisplayMode),
        render: fn => scope.run(() => computed(fn)),
        schedule: (options, now) => scope.run(() => schedule(now)(options)),
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
    assert.equal(first.displayTimeZoneLabel.value, 'Server Time')
    assert.equal(first.displayTimeZone.value, 'UTC')

    state.page.props.auth.user.time_display_mode = 'server'
    await nextTick()
    state.page.props.auth.user.time_display_mode = 'local'
    await nextTick()
    assert.deepEqual(cards.value, ['21:00', '21:00'])
    assert.equal(first.timeDisplayLabel.value, 'LT')
    assert.equal(first.displayTimeZoneLabel.value, 'Europe/London')
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
        assert.equal(api.displayTimeZoneLabel.value, navigationMessages[language].topbar.time_display.server)
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

test('availability inputs round-trip instants across LT/ST, fractional offsets and midnight', t => {
    const state = setup(t, { zone: 'Asia/Kathmandu' })
    const api = state.formatter()
    const instant = '2026-10-21T20:00:00.000Z'
    assert.equal(api.toDateTimeInput(instant), '2026-10-22T01:45')
    assert.equal(api.fromDateTimeInput('2026-10-22T01:45').toISOString(), instant)
    state.preference.setSharedTimeDisplayMode('server')
    assert.equal(api.toDateTimeInput(instant), '2026-10-21T20:00')
    assert.equal(api.fromDateTimeInput('2026-10-21T20:00').toISOString(), instant)
    assert.equal(api.fromDateTimeInput('2026-02-30T20:00'), null)
    assert.equal(api.toDateTimeInput('bad'), '')
})

test('DST input gaps and repeats are rejected but existing repeated-hour endpoints keep their offsets', t => {
    const state = setup(t)
    const api = state.formatter()
    assert.equal(api.fromDateTimeInput('2026-03-29T01:30'), null)
    assert.equal(api.fromDateTimeInput('2026-10-25T01:30'), null)
    const first = '2026-10-25T00:30:00.000Z'
    const second = '2026-10-25T01:30:00.000Z'
    assert.equal(api.fromDateTimeInput('2026-10-25T01:30', first).toISOString(), first)
    assert.equal(api.fromDateTimeInput('2026-10-25T01:30', second).toISOString(), second)
    state.preference.setSharedTimeDisplayMode('server')
    assert.equal(api.fromDateTimeInput('2026-03-29T01:30').toISOString(), '2026-03-29T01:30:00.000Z')
})

test('recurring calendar dates and clock labels do not shift with the display timezone', t => {
    const state = setup(t, { zone: 'America/Los_Angeles' })
    const api = state.formatter()
    for (const mode of ['local', 'server']) {
        state.preference.setSharedTimeDisplayMode(mode)
        assert.equal(api.formatDateOnly('2026-10-21', { dateStyle: 'short' }), '21/10/2026')
        assert.equal(api.formatWallTime('18:30'), '18:30')
        assert.equal(api.formatWallTime('24:00'), '00:00')
    }
})

test('schedule previews use the actual cycle and occurrence timezone, including overnight and DST', t => {
    const state = setup(t, { zone: 'Asia/Tokyo', mode: 'server' })
    const startsOn = ref('2026-03-25') // Wednesday: Monday belongs near the end of week A.
    const timeZone = ref('Europe/London')
    const options = { startsOn, timeZone, weeks: ref(2), repeats: ref(true) }
    const schedule = state.schedule(options, ref(new Date('2026-03-28T23:30:00Z')))
    assert.equal(schedule.dayDate(0, 1), '2026-03-30')
    assert.equal(schedule.minimumExceptionDate.value.toString(), '2026-03-28')
    const preview = state.render(() => schedule.blockPreview(0, 6, '22:00', '05:00'))
    assert.match(preview.value, /ST: Sat.*22:00.*Sun.*04:00/)
    state.preference.setSharedTimeDisplayMode('local')
    assert.match(preview.value, /LT: Sun.*07:00.*Sun.*13:00/)
    assert.equal(startsOn.value, '2026-03-25')
    assert.equal(timeZone.value, 'Europe/London')
    timeZone.value = 'Asia/Tokyo'
    assert.equal(schedule.minimumExceptionDate.value.toString(), '2026-03-29')
    for (const weeks of [1, 2, 4]) {
        const period = weeks * 7
        const later = calendar.parseDate(startsOn.value).add({ days: period * 3 + 2 }).toString()
        assert.equal(scheduleTime.availabilityCycleStart(startsOn.value, weeks, true, later), calendar.parseDate(startsOn.value).add({ days: period * 3 }).toString())
        assert.equal(scheduleTime.availabilityCycleStart(startsOn.value, weeks, false, later), startsOn.value)
    }
    assert.equal(scheduleTime.availabilityWindowInstants('2026-03-29', '01:30', '03:00', 'Europe/London'), null)
})

test('overnight exceptions remain editable until they end in the schedule timezone', () => {
    assert.equal(scheduleTime.isPendingAvailabilityException('2026-11-10', '22:00', '05:00', '2026-11-11T04:59'), true)
    assert.equal(scheduleTime.isPendingAvailabilityException('2026-11-10', '22:00', '05:00', '2026-11-11T05:00'), false)
    assert.equal(scheduleTime.isPendingAvailabilityException('2026-11-10', null, null, '2026-11-11T00:01'), false)
})

test('overnight timeline blocks continue onto the next day and wrap only repeating cycles', () => {
    for (const count of [1, 2, 4]) {
        const weeks = Array.from({ length: count }, () => Object.fromEntries(scheduleTime.availabilityDays.map(day => [day, { enabled: false, blocks: [] }])))
        const block = { id: 1, starts_at: '22:00', ends_at: '05:00', status: 'available' }
        weeks.at(-1).sunday = { enabled: true, blocks: [block] }
        const start = '2026-07-13'
        const sunday = calendar.parseDate(start).add({ days: count * 7 - 1 }).toString()
        const monday = calendar.parseDate(sunday).add({ days: 1 }).toString()
        const firstHalf = scheduleTime.availabilityTimelineDay(weeks, start, sunday, true)
        assert.deepEqual(firstHalf.map(entry => [entry.startMinute, entry.endMinute]), [[1320, 1440]])
        const carry = scheduleTime.availabilityTimelineDay(weeks, start, monday, true)
        assert.equal(carry[0].block, block) // Editing the continuation still edits the original rule.
        assert.equal(carry[0].sourceWeek, count - 1)
        assert.deepEqual([carry[0].startMinute, carry[0].endMinute, carry[0].sourceDate], [0, 300, sunday])
        assert.deepEqual(scheduleTime.availabilityTimelineDay(weeks, start, start, true), [])
        assert.deepEqual(scheduleTime.availabilityTimelineDay(weeks, start, calendar.parseDate(monday).add({ days: 7 }).toString(), false), [])
    }
})
