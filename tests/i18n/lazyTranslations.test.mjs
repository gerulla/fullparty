import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createI18n } from 'vue-i18n';
import { createTranslationLoader } from '../../resources/js/i18n/createTranslationLoader.js';
import { createPageResolver } from '../../resources/js/i18n/createPageResolver.js';
import { pageNamespaces } from '../../resources/js/i18n/pageNamespaces.js';

const composer = () => createI18n({ legacy: false, locale: 'de', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false, messages: {} }).global;

test('loads only requested features in the selected language and English', async () => {
    const calls = [];
    const files = {};
    for (const locale of ['en', 'de', 'fr', 'ja']) for (const ns of ['general', 'groups/activities', 'admin/quotas']) {
        files[`../../lang/${locale}/${ns}.json`] = async () => { calls.push(`${locale}/${ns}`); return { default: { title: locale + ns } }; };
    }
    const loader = createTranslationLoader(files);
    const i18n = composer();
    assert.equal(calls.length, 0);
    await loader.load(i18n, 'de', ['general', 'groups/activities']);
    assert.deepEqual(calls.sort(), ['de/general', 'de/groups/activities', 'en/general', 'en/groups/activities']);
    assert.equal(i18n.t('groups.activities.title'), 'degroups/activities');
    assert.equal(i18n.te('admin.quotas.title'), false);
});

test('shares concurrent requests, preserves sibling namespaces and caches later visits', async () => {
    let count = 0;
    const loader = createTranslationLoader(Object.fromEntries(['groups/activities', 'groups/members'].map(ns => [
        `../../lang/en/${ns}.json`, async () => { count++; return { title: ns }; },
    ])));
    const i18n = composer();
    await Promise.all([loader.load(i18n, 'en', ['groups/activities']), loader.load(i18n, 'en', ['groups/activities'])]);
    await loader.load(i18n, 'en', ['groups/activities', 'groups/members']);
    await loader.load(i18n, 'en', ['groups/members']);
    assert.equal(count, 2);
    assert.equal(i18n.t('groups.activities.title'), 'groups/activities');
    assert.equal(i18n.t('groups.members.title'), 'groups/members');
    const second = composer();
    await loader.load(second, 'en', ['groups/members']);
    assert.equal(count, 2);
    assert.equal(second.t('groups.members.title'), 'groups/members');
});

test('missing locale files and missing keys use English; unsupported locales do not fetch arbitrary paths', async () => {
    const calls = [];
    const loader = createTranslationLoader({
        '../../lang/en/general.json': async () => { calls.push('en'); return { title: 'Title', fallback: 'Fallback' }; },
        '../../lang/de/general.json': async () => { calls.push('de'); return { title: 'Titel' }; },
    });
    const i18n = composer();
    await loader.load(i18n, 'de', ['general']);
    assert.equal(i18n.t('general.title'), 'Titel');
    assert.equal(i18n.t('general.fallback'), 'Fallback');
    await loader.load(i18n, 'fr', ['general']);
    i18n.locale.value = 'fr';
    assert.equal(i18n.t('general.title'), 'Title');
    await loader.load(i18n, '../../anything', ['general']);
    assert.deepEqual(calls, ['en', 'de']);
    await assert.rejects(loader.load(i18n, 'en', ['missing']), /Missing fallback translations/);
});

test('a failed translation request can be retried without reloading successful files', async () => {
    let attempts = 0;
    let englishLoads = 0;
    const loader = createTranslationLoader({
        '../../lang/en/general.json': async () => { englishLoads++; return { title: 'Title' }; },
        '../../lang/de/general.json': async () => { if (++attempts === 1) throw Error('offline'); return { title: 'Titel' }; },
    });
    const i18n = composer();
    await assert.rejects(loader.load(i18n, 'de', ['general']), /offline/);
    await loader.load(i18n, 'de', ['general']);
    assert.equal(i18n.t('general.title'), 'Titel');
    assert.equal(attempts, 2);
    assert.equal(englishLoads, 1);
});

test('page resolution imports only the destination and waits for translations', async () => {
    let release;
    const ready = new Promise(resolve => { release = resolve; });
    const requested = [];
    const customLayout = {};
    const login = { default: { layout: customLayout } };
    let layoutLoads = 0;
    const resolve = createPageResolver({
        application: 'main', composer: {}, getLocale: () => 'ja',
        translations: { load: async (_, locale, namespaces) => { assert.equal(locale, 'ja'); assert.deepEqual(namespaces, ['general', 'meta', 'auth']); await ready; } },
        pages: {
            './Pages/auth/Login.vue': async () => { requested.push('login'); return login; },
            './Pages/Admin/Quotas.vue': async () => { requested.push('admin'); return {}; },
        },
        loadDefaultLayout: async () => { layoutLoads++; return {}; },
    });
    let resolved = false;
    const pending = resolve('auth/Login').then(result => { resolved = true; return result; });
    await Promise.resolve();
    assert.equal(resolved, false);
    release();
    assert.equal(await pending, login);
    assert.deepEqual(requested, ['login']);
    assert.equal(layoutLoads, 0);
    assert.equal(login.default.layout, customLayout);
});

test('default layouts are loaded lazily and failed page imports remain retryable', async () => {
    let attempts = 0;
    let layoutLoads = 0;
    const component = {};
    const layout = {};
    const resolve = createPageResolver({
        application: 'main', composer: {}, getLocale: () => 'en', translations: { load: async () => {} },
        pages: { './Pages/Dashboard/Dashboard.vue': async () => { if (++attempts === 1) throw Error('offline'); return { default: component }; } },
        loadDefaultLayout: async () => { layoutLoads++; return { default: layout }; },
    });
    await assert.rejects(resolve('Dashboard/Dashboard'), /offline/);
    await resolve('Dashboard/Dashboard');
    await resolve('Dashboard/Dashboard');
    assert.equal(component.layout, layout);
    assert.equal(layoutLoads, 1);
});

test('admin, calculator and planner dictionaries stay out of ordinary page groups', () => {
    for (const page of ['Home', 'auth/Login', 'Dashboard/Runs/MyRuns']) {
        assert.ok(pageNamespaces(page).every(ns => !ns.startsWith('admin/') && !['calculator', 'planner'].includes(ns)));
    }
    assert.deepEqual(pageNamespaces('Home', 'calculator'), ['auth', 'calculator', 'navigation']);
    assert.deepEqual(pageNamespaces('Home', 'planner'), ['auth', 'general', 'navigation', 'planner']);
    assert.throws(() => pageNamespaces('Unregistered/Page'), /not configured/);
});
