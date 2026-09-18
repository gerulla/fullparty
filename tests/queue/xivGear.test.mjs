import assert from 'node:assert/strict'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { isXivGearUrl, parseGearsetSnapshots, xivGearExtension } from '../../resources/js/utils/xivGear.ts'
import { hasRichTextContent, richTextPlainText } from '../../resources/js/utils/richText.ts'

const dom = new JSDOM('<!doctype html><html><body></body></html>')
for (const key of ['window', 'document', 'navigator', 'Node', 'HTMLElement', 'Element', 'MutationObserver', 'DOMParser']) Object.defineProperty(globalThis, key, { configurable: true, value: dom.window[key] })
globalThis.requestAnimationFrame = callback => setTimeout(callback, 0)
globalThis.cancelAnimationFrame = clearTimeout
const item = { id: 50053, names: { en: 'Shield', de: 'Schild', fr: 'Bouclier', ja: '盾' }, icon: 23001, itemLevel: 775, slot: 'OffHand', materia: [], relicStats: {} }
const snapshot = { version: 1, sourceUrl: 'https://xivgear.app/sl/example', importedAt: '2026-09-18T10:00:00+00:00', name: 'Set', description: '', job: 'PLD', level: 100, itemLevel: 715, itemLevelSync: 710, partyBonus: 5, gcd: 2.5, stats: { hp: 100000 }, items: [item], food: null }

test('copy and paste preserve all tabs, off-hand data and the editor display choice', () => {
    for (const display of ['expanded', 'compact']) {
        const content = { type: 'doc', content: [{ type: 'xivGear', attrs: { display, snapshots: [snapshot, { ...snapshot, name: 'Ornate' }] } }] }
        assert.equal(hasRichTextContent(content), true)
        assert.match(richTextPlainText(content), /Shield/)
        const first = new Editor({ extensions: [StarterKit, xivGearExtension()], content })
        const pasted = new Editor({ extensions: [StarterKit, xivGearExtension()], content: first.getHTML() })
        assert.deepEqual(JSON.parse(JSON.stringify(pasted.getJSON())), content)
        first.destroy(); pasted.destroy()
    }
})

test('clipboard snapshots reject unsafe URLs and malformed data before rendering', () => {
    assert.ok(parseGearsetSnapshots(JSON.stringify([snapshot])))
    for (const sourceUrl of ['javascript:alert(1)', 'https://evil.test', 'https://xivgear.app.evil.test', 'https://user@xivgear.app', 'https://xivgear.app:443/x']) {
        assert.equal(isXivGearUrl(sourceUrl), false)
        assert.equal(parseGearsetSnapshots(JSON.stringify([{ ...snapshot, sourceUrl }])), null)
    }
    for (const changes of [{ items: null }, { importedAt: 'bad' }, { food: {} }, { stats: { hp: '<script>' } }, { items: [{ ...item, icon: '../secret' }] }]) assert.equal(parseGearsetSnapshots(JSON.stringify([{ ...snapshot, ...changes }])), null)
})
