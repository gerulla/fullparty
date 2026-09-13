import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import { createI18n } from 'vue-i18n';
import * as jobs from '../../resources/js/utils/characterJobTranslations.ts';

const source = readFileSync(new URL('../../resources/js/utils/notificationPresentation.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const exports = {};
new Function('require', 'exports', compiled)(name => {
    if (name === '@/utils/characterJobTranslations') return jobs;
    if (name === '@/utils/dateTimeFormat') return { createRelativeTimeFormatter: (locale, options) => new Intl.RelativeTimeFormat(locale, options) };
    throw Error(`Unexpected import: ${name}`);
}, exports);

test('stored notification parameters render in the current language without mutating their payload', () => {
    for (const locale of ['de', 'fr', 'ja']) {
        const namespaces = Object.fromEntries(['characters', 'notifications'].map(name => [name,
            JSON.parse(readFileSync(new URL(`../../lang/${locale}/${name}.json`, import.meta.url), 'utf8')),
        ]));
        namespaces.example = '{class} — {phantom_job} — {position} — {designation} — {reason}';
        const i18n = createI18n({ legacy: false, locale, fallbackLocale: false, messages: { [locale]: namespaces } }).global;
        const notification = {
            body_key: 'example', payload: { designation_key: 'raid_leader', status: 'cancelled' },
            message_params: { class: 'Paladin', class_shorthand: 'PLD', phantom_job: 'Geomancer', position: 'Main Tank', position_key: 'mt', designation: 'Raid Leader', reason: 'Run cancelled.' },
        };
        const rendered = exports.resolveNotificationDescription(notification, i18n.t);
        assert.ok(rendered.includes(i18n.t('characters.jobs.classes.pld')));
        assert.ok(rendered.includes(i18n.t('characters.jobs.phantom.geomancer')));
        assert.ok(rendered.includes(i18n.t('characters.jobs.raid_positions.mt')));
        assert.ok(rendered.includes(i18n.t('notifications.designations.raid_leader')));
        assert.ok(rendered.includes(i18n.t('notifications.system_run_cancelled')));
        assert.equal(notification.message_params.reason, 'Run cancelled.');
        notification.message_params.reason = 'Please contact the group owner.';
        assert.ok(exports.resolveNotificationDescription(notification, i18n.t).includes('Please contact the group owner.'));
    }
});
