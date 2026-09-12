import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isConfirmationInputValid } from '../../resources/js/utils/confirmationInput.ts';

test('requires the exact confirmation phrase when configured', () => {
    const input = { label: 'Confirm', requiredValue: 'i am sure' };
    assert.equal(isConfirmationInputValid('i am sure', input), true);
    for (const value of ['', 'yes', 'I am sure', 'i am sure ', ' i am sure', 'i am']) {
        assert.equal(isConfirmationInputValid(value, input), false);
    }
});

test('preserves existing confirmation inputs without a required phrase', () => {
    assert.equal(isConfirmationInputValid(''), true);
    assert.equal(isConfirmationInputValid('', { label: 'Reason' }), true);
    assert.equal(isConfirmationInputValid('Some reason', { label: 'Reason' }), true);
});
