import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { pageNamespaces } from '../../resources/js/i18n/pageNamespaces.js';

const root = fileURLToPath(new URL('../../', import.meta.url));
const walk = dir => fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => entry.isDirectory()
    ? walk(path.join(dir, entry.name)) : [path.join(dir, entry.name)]);
const namespaces = walk(path.join(root, 'lang/en')).filter(file => file.endsWith('.json'))
    .map(file => path.relative(path.join(root, 'lang/en'), file).replaceAll('\\', '/').replace(/\.json$/, ''));
const dictionaries = Object.fromEntries(namespaces.map(ns => [ns, JSON.parse(fs.readFileSync(path.join(root, 'lang/en', ns + '.json'), 'utf8'))]));

function localImport(source, from) {
    const base = source.startsWith('@/') ? path.join(root, 'resources/js', source.slice(2))
        : source.startsWith('.') ? path.resolve(path.dirname(from), source) : null;
    return base ? [base, base + '.vue', base + '.ts', base + '.js', path.join(base, 'index.ts')]
        .find(file => fs.existsSync(file) && fs.statSync(file).isFile()) : null;
}

function referencedNamespaces(file, seen = new Set()) {
    if (seen.has(file)) return new Set();
    seen.add(file);
    const source = fs.readFileSync(file, 'utf8');
    const found = new Set();
    // Include literal keys, template prefixes and keys stored in helper objects.
    // Only actual dictionary paths count, so named Laravel routes are excluded.
    for (const match of source.matchAll(/['"`]([a-z][\w]*(?:\.[\w-]+)+(?:\.)?)(?:['"`]|\$\{)/g)) {
        const key = match[1];
        for (const ns of namespaces) if (key.startsWith(ns.replaceAll('/', '.') + '.')) {
            const rest = key.slice(ns.length + 1).replace(/\.$/, '');
            const value = rest ? rest.split('.').reduce((v, part) => v?.[part], dictionaries[ns]) : dictionaries[ns];
            if (value !== undefined) found.add(ns);
        }
    }
    for (const match of source.matchAll(/(?:\bfrom\s*|\bimport\s*\(\s*|\bimport\s*)['"]([^'"]+)['"]/g)) {
        const target = localImport(match[1], file);
        if (target) for (const ns of referencedNamespaces(target, seen)) found.add(ns);
    }
    return found;
}

test('every page declares the dictionaries used by its components, helpers and layout', () => {
    for (const [directory, application] of [['js', 'main'], ['planner', 'planner'], ['calculator', 'calculator']]) {
        const pagesRoot = path.join(root, 'resources', directory, 'Pages');
        for (const file of walk(pagesRoot).filter(file => file.endsWith('.vue'))) {
            const name = path.relative(pagesRoot, file).replaceAll('\\', '/').replace(/\.vue$/, '');
            const actual = new Set(pageNamespaces(name, application));
            const expected = referencedNamespaces(file);
            if (application === 'main' && !/layout\s*:/.test(fs.readFileSync(file, 'utf8'))) {
                for (const ns of referencedNamespaces(path.join(root, 'resources/js/Layouts/DefaultLayout.vue'))) expected.add(ns);
            }
            for (const ns of expected) assert.ok(actual.has(ns), `${application}:${name} needs ${ns}`);
            for (const ns of actual) assert.ok(namespaces.includes(ns), `Unknown dictionary ${ns}`);
        }
    }
});

test('all app entry points and dictionaries use lazy imports', () => {
    for (const app of ['js', 'planner', 'calculator']) for (const file of ['app.js', 'lang.js']) {
        const source = fs.readFileSync(path.join(root, 'resources', app, file), 'utf8');
        assert.ok(!source.includes('eager: true'), `${app}/${file} eagerly imports modules`);
        assert.ok(!/import\s*\{[^}]*\bmessages\b/.test(source));
    }
});

test('UI locale imports do not pull in the library-wide language index', () => {
    for (const file of walk(path.join(root, 'resources')).filter(file => /\.(vue|js|ts)$/.test(file))) {
        assert.ok(!/from\s+['"]@nuxt\/ui\/locale['"]/.test(fs.readFileSync(file, 'utf8')), file);
    }
});
