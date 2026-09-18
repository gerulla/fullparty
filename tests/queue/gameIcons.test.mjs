import assert from 'node:assert/strict'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { richTextExtensions } from '../../resources/js/utils/richTextExtensions.ts'
import { GameIconShortcodes } from '../../resources/js/utils/richTextGameIcons.ts'
import { findGameIcon, searchGameIcons, safeGameIconSource } from '../../resources/js/utils/gameIcons.ts'
import { richTextPlainText, hasRichTextContent } from '../../resources/js/utils/richText.ts'

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://fullparty.test' })
for (const key of ['window', 'document', 'navigator', 'Node', 'HTMLElement', 'Element', 'MutationObserver', 'DOMParser', 'getComputedStyle']) {
    Object.defineProperty(globalThis, key, { configurable: true, value: key === 'getComputedStyle' ? dom.window.getComputedStyle.bind(dom.window) : dom.window[key] })
}
globalThis.requestAnimationFrame = callback => setTimeout(callback, 0)
globalThis.cancelAnimationFrame = clearTimeout
const icons = [
    { key: 'class_pld', shortcode: 'pld', category: 'class', names: { en: 'Paladin', ja: 'ナイト' }, aliases: ['pld', 'paladin', 'ナイト'], src: '/reference-icons/character-classes/icons/pld.webp', job: '' },
    { key: 'role_tank', shortcode: 'tank', category: 'role', names: { en: 'Tank' }, aliases: ['tank'], src: '/role-icons/tank.png', job: '' },
    { key: 'bozja_26', shortcode: 'lost_cure', category: 'bozja_action', names: { en: 'Lost Cure', fr: 'Soin oublié' }, aliases: ['lost_cure', 'soin_oublié'], src: '/BozjaInfo/Lost%20Cure/icon.png', job: '' },
    { key: 'action_1', shortcode: 'pld_test', category: 'class_action', names: { en: 'Test' }, aliases: ['test'], src: '/CalculatorData/One/icon.png', job: 'PLD' },
    { key: 'action_2', shortcode: 'war_test', category: 'class_action', names: { en: 'Test' }, aliases: ['test'], src: '/CalculatorData/Two/icon.png', job: 'WAR' },
]
const createEditor = content => new Editor({ element: document.createElement('div'), extensions: [StarterKit, ...richTextExtensions(), GameIconShortcodes.configure({ icons: () => icons })], content: content ?? '<p></p>' })
function type(editor, text) {
    for (const character of text) {
        const { from, to } = editor.state.selection
        if (!editor.view.someProp('handleTextInput', handler => handler(editor.view, from, to, character))) editor.view.dispatch(editor.state.tr.insertText(character, from, to))
    }
}

test('search matches abbreviations, names, localized names and handles ambiguous action names', () => {
    assert.equal(findGameIcon(icons, 'PLD').key, 'class_pld')
    assert.equal(findGameIcon(icons, 'lostcure').key, 'bozja_26')
    assert.equal(findGameIcon(icons, 'test'), undefined)
    assert.equal(findGameIcon(icons, 'war_test').key, 'action_2')
    assert.equal(searchGameIcons(icons, 'soin oublie')[0].key, 'bozja_26')
    assert.equal(searchGameIcons(icons, 'ナイト')[0].key, 'class_pld')
    assert.equal(searchGameIcons(icons, 'test').length, 2)
})

test('typing a complete shortcode creates an inline icon and backspace undoes conversion', () => {
    const editor = createEditor()
    type(editor, 'Use :PLD:')
    assert.equal(editor.getJSON().content[0].content[1].type, 'gameIcon')
    assert.equal(editor.getText(), 'Use :pld:')
    assert.equal(richTextPlainText(editor.getJSON()), 'Use :pld:')
    assert.equal(hasRichTextContent(editor.getJSON()), true)
    assert.equal(editor.commands.undoInputRule(), true)
    assert.equal(editor.getText(), 'Use :PLD:')
    editor.destroy()
})

test('pasted shortcodes convert inside paragraphs, lists, headings and tables', () => {
    const editor = createEditor()
    editor.view.pasteHTML('<h2>:pld: Plan</h2><ul><li><p>:tank: front</p></li></ul><table><tr><td><p>:lost_cure:</p></td></tr></table>', new dom.window.Event('paste'))
    let count = 0
    editor.state.doc.descendants(node => { if (node.type.name === 'gameIcon') count++ })
    assert.equal(count, 3)
    const roundtrip = createEditor(editor.getHTML())
    assert.deepEqual(roundtrip.getJSON(), editor.getJSON())
    assert.equal(editor.commands.undo(), true)
    assert.equal(editor.getText(), '')
    editor.destroy(); roundtrip.destroy()
})

test('unknown names, ambiguous aliases, code and URLs stay untouched', () => {
    const editor = createEditor()
    type(editor, ':unknown: :test: https://example.com/:pld: ')
    assert.equal(editor.getText(), ':unknown: :test: https://example.com/:pld: ')
    editor.commands.setContent('<p></p>')
    editor.view.pasteHTML('<p><code>:pld:</code> <a href="https://example.com">:tank:</a></p><pre><code>:lost_cure:</code></pre>', new dom.window.Event('paste'))
    assert.equal(editor.getHTML().includes('data-game-icon-key'), false)
    editor.destroy()
})

test('adjacent pasted icons remain separate inline nodes', () => {
    const editor = createEditor()
    editor.view.pasteText(':pld::tank:', new dom.window.Event('paste'))
    assert.deepEqual(editor.getJSON().content[0].content.map(node => node.type), ['gameIcon', 'gameIcon'])
    editor.destroy()
})

test('bundled icon URLs cannot escape to arbitrary local or remote sources', () => {
    for (const src of ['https://evil.test/icon.png', '/role-icons/../secret.png', '/role-icons/%2e%2e/secret.png', '//role-icons/tank.png', '/role-icons/%5csecret.png', 'javascript:alert(1)']) assert.equal(safeGameIconSource(src), null)
    assert.equal(safeGameIconSource('/BozjaInfo/Lost%20Cure/icon.png'), '/BozjaInfo/Lost%20Cure/icon.png')
})
