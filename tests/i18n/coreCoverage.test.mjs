import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createI18n } from 'vue-i18n';

const root = fileURLToPath(new URL('../../lang/', import.meta.url));
const walk = dir => fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => entry.isDirectory()
    ? walk(path.join(dir, entry.name)) : [path.join(dir, entry.name)]);
const flatten = (value, prefix = '', result = {}) => {
    for (const [key, child] of Object.entries(value)) {
        const name = prefix ? `${prefix}.${key}` : key;
        if (child && typeof child === 'object') flatten(child, name, result);
        else result[name] = child;
    }
    return result;
};

test('every core dictionary has nonempty entries in all four languages', () => {
    for (const file of walk(path.join(root, 'en')).filter(file => file.endsWith('.json'))) {
        const relative = path.relative(path.join(root, 'en'), file);
        if (/^(calculator|planner)\./.test(relative)) continue;
        const english = flatten(JSON.parse(fs.readFileSync(file, 'utf8')));
        for (const locale of ['de', 'fr', 'ja']) {
            const translated = flatten(JSON.parse(fs.readFileSync(path.join(root, locale, relative), 'utf8')));
            assert.deepEqual(Object.keys(translated).sort(), Object.keys(english).sort(), `${locale}/${relative}`);
            for (const [key, value] of Object.entries(translated)) {
                assert.equal(typeof value, typeof english[key], `${locale}/${relative}:${key}`);
                if (typeof value === 'string') assert.ok(value.trim(), `${locale}/${relative}:${key} is empty`);
            }
        }
    }
});

test('framework error and authentication messages have matching language catalogs', () => {
    const english = JSON.parse(fs.readFileSync(path.join(root, 'en.json'), 'utf8'));
    for (const locale of ['de', 'fr', 'ja']) {
        const translated = JSON.parse(fs.readFileSync(path.join(root, locale + '.json'), 'utf8'));
        assert.deepEqual(Object.keys(translated).sort(), Object.keys(english).sort());
        for (const [key, value] of Object.entries(translated)) assert.ok(value.trim(), `${locale}: ${key}`);
    }
});

test('legal text renders configured contact details and translated fallbacks in every language', () => {
    for (const locale of ['en', 'de', 'fr', 'ja']) {
        const legal = JSON.parse(fs.readFileSync(path.join(root, locale, 'legal.json'), 'utf8'));
        const i18n = createI18n({ legacy: false, locale, fallbackLocale: false, messages: { [locale]: { legal } } }).global;
        const params = {
            controller: 'Example Operator', contact: 'contact@example.test',
            operator_sentence: i18n.t('legal.privacy.operator_sentence', { controller: 'Example Operator' }),
            operator_details: i18n.t('legal.privacy.operator_details', { controller: 'Example Operator' }),
            contact_line: i18n.t('legal.cookies.contact_line', { controller: 'Example Operator', contact: 'contact@example.test' }),
        };
        for (const key of Object.keys(flatten(legal))) {
            const rendered = i18n.t(`legal.${key}`, params);
            assert.ok(rendered && rendered !== `legal.${key}`);
            assert.ok(!/\{\w+\}/.test(rendered), `${locale}: ${key}`);
        }
        assert.ok(params.operator_sentence.includes('Example Operator'));
        assert.ok(params.contact_line.includes('contact@example.test'));
    }
});
