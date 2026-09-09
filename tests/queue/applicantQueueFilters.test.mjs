import assert from 'node:assert/strict';
import { test } from 'node:test';
import { matchesQueuePartyLeadFilter, matchesQueueRoleFilter } from '../../resources/js/utils/applicantQueueFilters.ts';

const roles = ['tank', 'healer', 'melee dps', 'physical ranged dps', 'magic ranged dps'];
const classField = {
    key: 'character_class',
    application_key: 'preferred_character_classes',
    source: 'character_classes',
    options: roles.map((role, index) => ({ key: String(index + 1), meta: { role } })),
};
const classAnswer = (raw_value) => ({ question_key: classField.application_key, raw_value });
const leadAnswer = (raw_value) => ({ question_key: 'wants_to_party_lead', raw_value });

for (const [index, role] of roles.entries()) {
    test(`matches submitted classes in the ${role} role`, () => {
        assert.equal(matchesQueueRoleFilter([classAnswer([String(index + 1)])], [classField], [role]), true);
        assert.equal(matchesQueueRoleFilter([classAnswer([String((index + 1) % 5 + 1)])], [classField], [role]), false);
    });
}

test('matches any selected role and handles numeric and scalar class answers', () => {
    assert.equal(matchesQueueRoleFilter([classAnswer([2, 3])], [classField], ['tank', 'healer']), true);
    assert.equal(matchesQueueRoleFilter([classAnswer(1)], [classField], ['tank']), true);
    assert.equal(matchesQueueRoleFilter([classAnswer(['4', '5'])], [classField], ['tank', 'healer']), false);
});

test('ignores phantom jobs, unrelated answers, and missing class choices', () => {
    assert.equal(matchesQueueRoleFilter([{ question_key: 'preferred_phantom_jobs', raw_value: ['1'] }], [classField], ['tank']), false);
    assert.equal(matchesQueueRoleFilter([classAnswer(null)], [classField], ['tank']), false);
    assert.equal(matchesQueueRoleFilter([], [classField], ['tank']), false);
    assert.equal(matchesQueueRoleFilter([], [classField], []), true);
    assert.equal(matchesQueueRoleFilter([], [], ['tank']), true);
});

test('honors the application options and accepts Any only when the form offers it', () => {
    const restricted = { ...classField, filter_options: [classField.options[1]] };
    assert.equal(matchesQueueRoleFilter([classAnswer(['1'])], [restricted], ['tank']), false);
    assert.equal(matchesQueueRoleFilter([classAnswer(['any'])], [classField], ['tank']), false);
    const withAny = { ...classField, filter_options: [...classField.options, { key: 'any' }] };
    assert.equal(matchesQueueRoleFilter([classAnswer(['any'])], [withAny], ['tank']), true);
});

test('includes only affirmative party lead answers when enabled', () => {
    for (const value of [true, 1, '1', 'true']) {
        assert.equal(matchesQueuePartyLeadFilter([leadAnswer(value)], 'wants_to_party_lead', true), true);
    }
    for (const value of [false, 0, '0', 'false', null, undefined, '', []]) {
        assert.equal(matchesQueuePartyLeadFilter([leadAnswer(value)], 'wants_to_party_lead', true), false);
    }
    assert.equal(matchesQueuePartyLeadFilter([], 'wants_to_party_lead', true), false);
    assert.equal(matchesQueuePartyLeadFilter([], 'wants_to_party_lead', false), true);
    assert.equal(matchesQueuePartyLeadFilter([], null, true), true);
});

test('combines role and party lead preferences without altering answers', () => {
    const answers = [classAnswer(['1', '2']), leadAnswer(true)];
    const original = structuredClone(answers);
    assert.equal(matchesQueueRoleFilter(answers, [classField], ['healer'])
        && matchesQueuePartyLeadFilter(answers, 'wants_to_party_lead', true), true);
    assert.equal(matchesQueueRoleFilter(answers, [classField], ['melee dps'])
        && matchesQueuePartyLeadFilter(answers, 'wants_to_party_lead', true), false);
    assert.deepEqual(answers, original);
});
