import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatApplicationAnswerSummary } from '../../resources/js/utils/applicationAnswerSummary.ts';

const question = {
    key: 'preferred_holsters', type: 'holster_pair_list', source: 'bozja_holsters',
    options: [
        { key: '1', label: { en: 'Melee Prepop', de: 'Nahkampf Vorbereitung' } },
        { key: '2', label: { en: 'Melee Refill', de: 'Nahkampf Nachschub' } },
        { key: '3', label: { en: 'Ranged Prepop' } },
        { key: '4', label: { en: 'Ranged Refill' } },
    ],
};
const t = key => ({
    'general.yes': 'Yes', 'general.no': 'No',
    'groups.activities.application.holsters.prepop': 'Pre-pop',
    'groups.activities.application.holsters.refill': 'Refill',
})[key] ?? key;
const optionLabel = (q, key) => q.options.find(option => option.key === key)?.label.en ?? key;
const format = (value, type = question.type) => formatApplicationAnswerSummary({ ...question, type }, value, optionLabel, t);

test('renders every holster pair by name on its own line with numeric or string IDs', () => {
    const pairs = [{ prepop_id: 1, refill_id: 2 }, { prepop_id: '3', refill_id: '4' }];
    const original = structuredClone(pairs);
    assert.deepEqual(format(pairs), {
        value: 'Pre-pop: Melee Prepop + Refill: Melee Refill\nPre-pop: Ranged Prepop + Refill: Ranged Refill',
        isLongText: true,
    });
    assert.deepEqual(pairs, original);
});

test('uses localized holster names and existing localized field labels', () => {
    const result = formatApplicationAnswerSummary(question, [{ prepop_id: '1', refill_id: '2' }],
        (q, key) => q.options.find(option => option.key === key)?.label.de ?? key,
        key => key.endsWith('.prepop') ? 'Vorbereitung' : 'Nachschub');
    assert.equal(result.value, 'Vorbereitung: Nahkampf Vorbereitung + Nachschub: Nahkampf Nachschub');
});

test('never coerces missing, malformed or empty holster data into object strings', () => {
    for (const value of [null, undefined, '', [], {}, [null, {}, [], 'bad', { prepop_id: {} }]]) {
        assert.equal(format(value), null);
    }
    assert.equal(format([{ prepop_id: 1 }]).value, 'Pre-pop: Melee Prepop');
    assert.equal(format([{ prepop_id: 99, refill_id: 100 }]).value, 'Pre-pop: 99 + Refill: 100');
});

test('preserves legacy holster selects and other summary answer types', () => {
    assert.deepEqual(format([1, '2'], 'multi_select'), { value: 'Melee Prepop, Melee Refill', isLongText: false });
    assert.equal(format('1', 'single_select').value, 'Melee Prepop');
    assert.equal(format(false, 'boolean').value, 'No');
    assert.equal(format(true, 'boolean').value, 'Yes');
    assert.deepEqual(format('A note', 'textarea'), { value: 'A note', isLongText: true });
    assert.deepEqual(format(0, 'number'), { value: '0', isLongText: false });
    assert.equal(format({}, 'text'), null);
    assert.equal(format([{}], 'multi_select'), null);
});
