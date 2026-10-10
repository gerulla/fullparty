import assert from 'node:assert/strict';
import { test } from 'node:test';
import { filterMyRuns as filterRuns, groupMyRunsByDay } from '../../resources/js/utils/myRuns.ts';
import { availableActivityTypes } from '../../resources/js/utils/activityTypes.ts';

const filterMyRuns = (items, state, commitments, timeZone = 'UTC') => filterRuns(items, state, commitments, '2026-09-09', timeZone);

const filters = (patch = {}) => ({ date: '2026-09-09', search: '', activityTypeId: null, groupIds: [1, 2], appliedOnly: false, hideOverlapping: false, ...patch });
const run = (id, starts_at, patch = {}) => ({ id, starts_at, duration_hours: 2, group: { id: 1 }, has_existing_application: false, ...patch });

test('lists only activity types represented in eligible runs, once across groups and versions', () => {
    const ba = { id: 10, slug: 'ba', draft_name: { en: 'The Baldesion Arsenal', ja: 'バルデシオンアーセナル' } };
    const drs = { id: 20, slug: 'drs', draft_name: { en: 'Delubrum Reginae (Savage)' } };
    const items = [
        run(1, '2026-09-09T18:00:00Z', { activity_type: ba, activity_type_version_id: 1 }),
        run(2, '2026-09-10T18:00:00Z', { activity_type: ba, activity_type_version_id: 2, group: { id: 2 } }),
        run(3, '2026-09-10T18:00:00Z', { activity_type: drs }),
        run(4, '2026-09-08T18:00:00Z', { activity_type: { id: 30 } }),
        run(5, null, { activity_type: { id: 40 } }),
        run(6, 'invalid', { activity_type: { id: 50 } }),
        run(7, '2026-09-10T18:00:00Z', { activity_type: { id: 60 }, group: { id: 99 } }),
        run(8, '2026-09-10T18:00:00Z', { activity_type: { id: null } }),
        run(9, '2026-09-10T18:00:00Z'),
    ];
    assert.deepEqual(availableActivityTypes(filterMyRuns(items, filters(), [])), [ba, drs]);
    assert.deepEqual(availableActivityTypes([]), []);
});

test('filters by stable activity type ID and restores every type when cleared', () => {
    const items = [
        run(1, '2026-09-09T18:00:00Z', { activity_type: { id: 10 }, activity_type_version_id: 1 }),
        run(2, '2026-09-10T18:00:00Z', { activity_type: { id: 20 } }),
        run(3, '2026-09-11T18:00:00Z', { activity_type: { id: 10 }, activity_type_version_id: 2 }),
        run(4, '2026-09-12T18:00:00Z'),
    ];
    const filtered = filterMyRuns(items, filters({ activityTypeId: 10 }), []);
    assert.deepEqual(filtered.map(item => item.id), [1, 3]);
    assert.deepEqual(groupMyRunsByDay(filtered, 'UTC').map(day => day.date), ['2026-09-09', '2026-09-11']);
    assert.deepEqual(filterMyRuns(items, filters({ activityTypeId: null }), []).map(item => item.id), [1, 2, 3, 4]);
    assert.deepEqual(filterMyRuns(items, filters({ activityTypeId: 99 }), []), []);
});

test('activity type filtering composes with existing filters and keeps conflicts from other types', () => {
    const items = [
        run(1, '2026-09-09T18:00:00Z', { title: 'Reclear', activity_type: { id: 10 } }),
        run(2, '2026-09-10T18:00:00Z', { title: 'Reclear', activity_type: { id: 10 }, has_existing_application: true }),
        run(3, '2026-09-11T18:00:00Z', { title: 'Reclear', activity_type: { id: 20 }, has_existing_application: true }),
        run(4, '2026-09-12T18:00:00Z', { title: 'Prog', activity_type: { id: 10 }, has_existing_application: true }),
        run(5, '2026-09-13T18:00:00Z', { title: 'Reclear', activity_type: { id: 10 }, group: { id: 2 }, has_existing_application: true }),
    ];
    const busy = [run(9, '2026-09-09T18:00:00Z', { activity_type: { id: 20 }, group: { id: 99 } })];
    const selected = filters({ activityTypeId: 10, groupIds: [1], search: 'reclear', hideOverlapping: true });
    assert.deepEqual(filterMyRuns(items, selected, busy).map(item => item.id), [2]);
    assert.deepEqual(filterMyRuns(items, { ...selected, hideOverlapping: false, appliedOnly: true }, []).map(item => item.id), [2]);
});

test('searches group names, titles and localized activity names without case sensitivity', () => {
    const items = [
        run(1, '2026-09-09T18:00:00Z', { group: { id: 1, name: 'Aether Bloom' }, title: 'Weekly Reclear', activity_type: { draft_name: { en: 'The Howling Eye', ja: 'ハウリングアイ' } } }),
        run(2, '2026-09-10T18:00:00Z', { group: { id: 2, name: 'Light Raiders' }, title: 'Fresh Prog', activity_type: { draft_name: { en: 'The Forked Tower' } } }),
    ];
    for (const search of ['BLOOM', 'reclear', 'howling', 'ハウリング', '  bloom   WEEKLY eye  ']) {
        assert.deepEqual(filterMyRuns(items, filters({ search }), []).map(item => item.id), [1]);
    }
    assert.deepEqual(filterMyRuns(items, filters({ search: 'tower' }), []).map(item => item.id), [2]);
    assert.deepEqual(filterMyRuns(items, filters({ search: 'bloom tower' }), []), []);
    assert.equal(filterMyRuns(items, filters({ search: '   ' }), []).length, 2);
});

test('search composes with group, applied and overlapping filters without changing commitments', () => {
    const items = [run(1, '2026-09-09T18:00:00Z', { title: 'Reclear' }), run(2, '2026-09-10T18:00:00Z', { title: 'Reclear', has_existing_application: true })];
    const busy = [run(9, '2026-09-09T18:00:00Z', { title: 'Different title' })];
    assert.deepEqual(filterMyRuns(items, filters({ search: 'reclear', hideOverlapping: true }), busy).map(item => item.id), [2]);
    assert.deepEqual(filterMyRuns(items, filters({ search: 'reclear', appliedOnly: true }), []).map(item => item.id), [2]);
    assert.deepEqual(filterMyRuns(items, filters({ search: 'reclear', groupIds: [2] }), []), []);
    assert.deepEqual(filterMyRuns([run(3, '2026-09-09T18:00:00Z')], filters({ search: 'missing' }), []), []);
});

test('groups all dates chronologically and sorts runs without mutating the source', () => {
    const items = [run(3, '2026-09-10T18:00:00Z'), run(2, '2026-09-09T21:00:00Z'), run(1, '2026-09-09T18:00:00Z')];
    const days = groupMyRunsByDay(filterMyRuns(items, filters({ date: '2026-09-10' }), []), 'UTC');
    assert.deepEqual(days.map(day => day.date), ['2026-09-09', '2026-09-10']);
    assert.deepEqual(days.map(day => day.activities.map(item => item.id)), [[1, 2], [3]]);
    assert.deepEqual(items.map(item => item.id), [3, 2, 1]);
});

test('groups by the display timezone including a daylight-saving transition', () => {
    const items = [run(1, '2026-03-29T23:30:00Z'), run(2, '2026-03-29T00:30:00Z')];
    assert.deepEqual(groupMyRunsByDay(items, 'UTC').map(day => day.date), ['2026-03-29']);
    assert.deepEqual(groupMyRunsByDay(items, 'Europe/London').map(day => day.date), ['2026-03-29', '2026-03-30']);
});

test('only includes today onward in the display timezone, retaining earlier runs today', () => {
    const items = [run(1, '2026-09-08T22:30:00Z'), run(2, '2026-09-08T23:30:00Z'), run(3, '2026-09-09T01:00:00Z')];
    assert.deepEqual(filterMyRuns(items, filters(), [], 'Europe/London').map(item => item.id), [2, 3]);
    assert.deepEqual(filterMyRuns(items, filters(), [], 'UTC').map(item => item.id), [3]);
});

test('filters groups and applications and skips missing or malformed dates', () => {
    const items = [run(1, '2026-09-09T18:00:00Z', { has_existing_application: true }), run(2, '2026-09-09T18:00:00Z', { group: { id: 2 } }), run(3, null), run(4, 'invalid')];
    assert.deepEqual(filterMyRuns(items, filters({ appliedOnly: true }), []).map(item => item.id), [1]);
    assert.deepEqual(filterMyRuns(items, filters({ groupIds: [2] }), []).map(item => item.id), [2]);
    assert.deepEqual(filterMyRuns(items, filters({ groupIds: [] }), []), []);
});

test('hides conflicts against commitments from unselected groups but keeps applied and assigned runs', () => {
    const busy = [run(8, '2026-09-09T18:00:00Z', { group: { id: 99 } }), run(2, '2026-09-09T19:00:00Z')];
    const items = [run(1, '2026-09-09T19:00:00Z'), run(2, '2026-09-09T19:00:00Z'), run(3, '2026-09-09T19:00:00Z', { has_existing_application: true }), run(4, '2026-09-09T21:00:00Z')];
    assert.deepEqual(filterMyRuns(items, filters({ hideOverlapping: true }), busy).map(item => item.id), [2, 3, 4]);
    assert.equal(filterMyRuns(items, filters(), busy).length, 4);
});

test('handles cross-midnight overlaps and allows back-to-back runs', () => {
    const busy = [run(9, '2026-09-09T23:00:00Z')];
    const items = [run(1, '2026-09-10T00:30:00Z'), run(2, '2026-09-10T01:00:00Z'), run(3, '2026-09-09T21:00:00Z')];
    assert.deepEqual(filterMyRuns(items, filters({ hideOverlapping: true }), busy).map(item => item.id), [2, 3]);
});

test('does not invent a duration when it is missing, but detects identical starts', () => {
    const busy = [run(9, '2026-09-09T18:00:00Z', { duration_hours: null })];
    const items = [run(1, '2026-09-09T18:00:00Z'), run(2, '2026-09-09T19:00:00Z')];
    assert.deepEqual(filterMyRuns(items, filters({ hideOverlapping: true }), busy).map(item => item.id), [2]);
});
