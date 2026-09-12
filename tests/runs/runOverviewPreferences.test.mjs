import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
import { effectScope } from 'vue';
import { useRunOverviewPreferences } from '../../resources/js/composables/useRunOverviewPreferences.ts';

let jar;
let writes;
let scopes;
const originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document');
const originalWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');
const mount = () => {
    const scope = effectScope();
    scopes.push(scope);
    return scope.run(useRunOverviewPreferences);
};

beforeEach(() => {
    jar = new Map();
    writes = [];
    scopes = [];
    Object.defineProperty(globalThis, 'window', { configurable: true, value: { location: { protocol: 'https:' } } });
    Object.defineProperty(globalThis, 'document', { configurable: true, value: {
        get cookie() { return [...jar].map(([key, value]) => `${key}=${value}`).join('; '); },
        set cookie(value) {
            writes.push(value);
            const [key, entry] = value.split(';')[0].split('=');
            jar.set(key, entry);
        },
    } });
});

afterEach(() => {
    scopes.forEach(scope => scope.stop());
    for (const [key, descriptor] of [['document', originalDocument], ['window', originalWindow]]) {
        if (descriptor) Object.defineProperty(globalThis, key, descriptor);
        else delete globalThis[key];
    }
});

test('defaults to distinct DPS colors and alphabetic parties without creating cookies', () => {
    const prefs = mount();
    assert.equal(prefs.plainDpsEnabled.value, false);
    assert.equal(prefs.numberedSecondaryPartiesEnabled.value, false);
    assert.equal(prefs.allianceProgressEnabled.value, false);
    assert.equal(writes.length, 0);
});

test('both preferences persist independently across overview mounts and can be turned off', () => {
    const first = mount();
    first.plainDpsEnabled.value = true;
    const second = mount();
    assert.equal(second.plainDpsEnabled.value, true);
    assert.equal(second.numberedSecondaryPartiesEnabled.value, false);
    second.numberedSecondaryPartiesEnabled.value = true;
    const third = mount();
    assert.equal(third.plainDpsEnabled.value, true);
    assert.equal(third.numberedSecondaryPartiesEnabled.value, true);
    third.plainDpsEnabled.value = false;
    third.numberedSecondaryPartiesEnabled.value = false;
    const fourth = mount();
    assert.equal(fourth.plainDpsEnabled.value, false);
    assert.equal(fourth.numberedSecondaryPartiesEnabled.value, false);
    assert.ok(writes.every(value => value.includes('Path=/; Max-Age=31536000; SameSite=Lax; Secure')));
});

test('ignores malformed values and works on HTTP development sites', () => {
    jar.set('fullparty_overview_plain_dps', 'unexpected');
    jar.set('other_fullparty_overview_numbered_parties', '1');
    window.location.protocol = 'http:';
    const prefs = mount();
    assert.equal(prefs.plainDpsEnabled.value, false);
    assert.equal(prefs.numberedSecondaryPartiesEnabled.value, false);
    prefs.plainDpsEnabled.value = true;
    assert.ok(!writes[0].includes('Secure'));
});

test('alliance progression is remembered independently of role and party label settings', () => {
    const first = mount();
    first.allianceProgressEnabled.value = true;
    const second = mount();
    assert.equal(second.allianceProgressEnabled.value, true);
    assert.equal(second.plainDpsEnabled.value, false);
    assert.equal(second.numberedSecondaryPartiesEnabled.value, false);
    second.allianceProgressEnabled.value = false;
    assert.equal(mount().allianceProgressEnabled.value, false);
});

test('blocked cookies do not prevent toggles from working', () => {
    Object.defineProperty(document, 'cookie', {
        get() { throw new Error('Blocked'); },
        set() { throw new Error('Blocked'); },
    });
    const prefs = mount();
    prefs.plainDpsEnabled.value = true;
    assert.equal(prefs.plainDpsEnabled.value, true);
});

test('can initialize without a browser document', () => {
    delete globalThis.document;
    assert.equal(mount().plainDpsEnabled.value, false);
});
