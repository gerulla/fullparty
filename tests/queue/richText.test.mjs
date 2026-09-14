import assert from 'node:assert/strict'
import test from 'node:test'
import { spawnSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { JSDOM } from 'jsdom'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import { richTextExtensions } from '../../resources/js/utils/richTextExtensions.ts'
import { emptyRichTextDocument, hasRichTextContent, richTextPlainText, safeEditorUrl } from '../../resources/js/utils/richText.ts'

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://fullparty.test' })
for (const key of ['window', 'document', 'navigator', 'Node', 'HTMLElement', 'Element', 'MutationObserver', 'DOMParser', 'getComputedStyle']) {
    Object.defineProperty(globalThis, key, { configurable: true, value: key === 'getComputedStyle' ? dom.window.getComputedStyle.bind(dom.window) : dom.window[key] })
}
globalThis.requestAnimationFrame = callback => setTimeout(callback, 0)
globalThis.cancelAnimationFrame = clearTimeout
const createEditor = (content, options = {}) => new Editor({ element: document.createElement('div'), extensions: [StarterKit, Image, ...richTextExtensions()], content: content ?? emptyRichTextDocument(), enableContentCheck: true, ...options })
const textDocument = text => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text }] }] })

test('Discord article pastes keep their text, structure and links while normalizing foreign styles', () => {
    const html = readFileSync(new URL('../Fixtures/rich-text/discord-guide.html', import.meta.url), 'utf8')
    const original = createEditor(html, { enableContentCheck: false })
    const editor = createEditor()
    assert.equal(editor.view.pasteHTML(html, new dom.window.Event('paste')), true)
    assert.equal(editor.getText(), original.getText())
    assert.match(editor.getHTML(), /<h1><strong>Ray of Expulsion Afar/)
    assert.match(editor.getHTML(), /<blockquote>/)
    assert.match(editor.getHTML(), /<ul>/)
    assert.match(editor.getHTML(), /sourpuh\.github\.io\/waymarkstudio\?preset=wms1\./)
    assert.match(editor.getHTML(), /https:\/\/raidplan\.io\/plan\/EJzwrqkqyWdPlBWL/)
    assert.doesNotMatch(editor.getHTML(), /gg sans|line-height: 22px/)
    const saved = backendDocument(editor.getJSON())
    const reloaded = createEditor(saved.document)
    assert.equal(reloaded.getText(), editor.getText())
    original.destroy(); editor.destroy(); reloaded.destroy()
})

test('formatted line breaks pasted from chat survive backend storage', () => {
    const editor = createEditor()
    editor.view.pasteHTML('<p><strong>First line<br>Second line</strong></p>', new dom.window.Event('paste'))
    const saved = backendDocument(editor.getJSON())
    assert.equal(saved.html, '<p><strong>First line<br>Second line</strong></p>')
    editor.destroy()
})

test('already-pasted Discord styles can be saved without asking the user to rewrite their draft', () => {
    const editor = createEditor('<p><span style="font-family: gg sans, Arial; font-size: 16px; line-height: normal; background-color: rgba(0, 0, 0, 0.2)">Existing draft</span></p>', { enableContentCheck: false })
    const saved = backendDocument(editor.getJSON())
    assert.match(saved.html, /font-size: 16px/)
    assert.doesNotMatch(saved.html, /gg sans|normal|rgba/)
    assert.equal(richTextPlainText(saved.document), 'Existing draft')
    editor.destroy()
})

function backendDocument(document) {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $service = app(App\\Services\\RichText\\RichTextDocument::class); $document = $service->validate(json_decode(stream_get_contents(STDIN), true)); echo json_encode(['document' => $document, 'html' => $service->html($document)]);`], { input: JSON.stringify(document), encoding: 'utf8', cwd: new URL('../../', import.meta.url) })
    assert.equal(result.status, 0, String(result.error ?? '') + result.stderr + result.stdout)
    return JSON.parse(result.stdout)
}

test('heading, paragraph, formatting, lists and undo work on a real editor', () => {
    const editor = createEditor(textDocument('Bridge assignments'))
    editor.commands.selectAll()
    editor.commands.toggleHeading({ level: 2 })
    assert.equal(editor.getJSON().content[0].type, 'heading')
    editor.commands.setParagraph()
    assert.equal(editor.getJSON().content[0].type, 'paragraph')
    editor.chain().toggleBold().toggleItalic().setFontSize('24px').setFontFamily('Georgia').setColor('#ff0000').setTextAlign('center').run()
    assert.match(editor.getHTML(), /font-size: 24px/)
    assert.match(editor.getHTML(), /text-align: center/)
    editor.commands.toggleTaskList()
    assert.equal(editor.getJSON().content[0].type, 'taskList')
    const saved = backendDocument(editor.getJSON())
    const reloaded = createEditor(saved.document)
    reloaded.state.doc.check()
    assert.equal(reloaded.getText(), editor.getText())
    editor.commands.undo()
    assert.equal(editor.getJSON().content[0].type, 'paragraph')
    editor.destroy(); reloaded.destroy()
})

test('tables can add rows and columns, merge cells, and survive backend storage', () => {
    const editor = createEditor()
    editor.commands.insertTable({ rows: 2, cols: 2, withHeaderRow: true })
    editor.commands.insertContent('West')
    editor.commands.addRowAfter()
    editor.commands.addColumnAfter()
    const table = editor.getJSON().content.find(node => node.type === 'table')
    assert.equal(table.content.length, 3)
    assert.equal(table.content[0].content.length, 3)
    editor.commands.setCellAttribute('colwidth', [140])
    const positions = []
    editor.state.doc.descendants((node, pos) => { if (node.type.name === 'tableHeader' || node.type.name === 'tableCell') positions.push(pos) })
    editor.commands.setCellSelection({ anchorCell: positions[0], headCell: positions[5] })
    assert.equal(editor.commands.mergeCells(), true)
    const saved = backendDocument(editor.getJSON())
    const reloaded = createEditor(saved.document)
    reloaded.state.doc.check()
    assert.match(saved.html, /data-colwidth="140(?:,0)*"/)
    assert.match(reloaded.getHTML(), /West/)
    assert.match(reloaded.getHTML(), /rowspan="2"/)
    editor.commands.deleteTable()
    assert.equal(editor.getJSON().content.some(node => node.type === 'table'), false)
    editor.destroy(); reloaded.destroy()
})

test('color and highlight changes preserve the selected text and picker focus', () => {
    const editor = createEditor(textDocument('Selected untouched'))
    const pickerInput = document.createElement('input')
    document.body.append(pickerInput)
    try {
        editor.commands.setTextSelection({ from: 1, to: 9 })
        pickerInput.focus()
        editor.chain().setColor('#123456').run()
        editor.chain().setHighlight({ color: '#facc15' }).run()
        editor.chain().setColor('#654321').run()

        assert.equal(document.activeElement, pickerInput)
        assert.equal(editor.state.selection.from, 1)
        assert.equal(editor.state.selection.to, 9)
        const [selected, untouched] = editor.getJSON().content[0].content
        assert.equal(selected.text, 'Selected')
        assert.equal(selected.marks.find(mark => mark.type === 'textStyle').attrs.color, '#654321')
        assert.equal(selected.marks.find(mark => mark.type === 'highlight').attrs.color, '#facc15')
        assert.equal(untouched.text, ' untouched')
        assert.equal(untouched.marks, undefined)
    } finally {
        editor.destroy()
        pickerInput.remove()
    }
})

test('managed images and link titles survive JSON storage with safe HTML', () => {
    const editor = createEditor(textDocument('FullParty'))
    editor.commands.selectAll()
    editor.commands.setLink({ href: 'https://fullparty.gg' })
    editor.commands.updateAttributes('link', { title: 'Visit FullParty' })
    editor.commands.setTextSelection(editor.state.doc.content.size - 1)
    editor.commands.setImage({ src: '/resource-assets/11111111-1111-1111-1111-111111111111', alt: 'Bridge map', title: 'Positions' })
    editor.commands.updateAttributes('image', { width: 640, height: 320 })
    const saved = backendDocument(editor.getJSON())
    const reloaded = createEditor(saved.document)
    reloaded.state.doc.check()
    assert.match(saved.html, /title="Visit FullParty"/)
    assert.match(saved.html, /width="640"/)
    assert.match(saved.html, /nofollow noopener noreferrer/)
    assert.equal(reloaded.getJSON().content.find(node => node.type === 'image').attrs.alt, 'Bridge map')
    editor.destroy(); reloaded.destroy()
})

test('text extraction preserves spaces and detects image-only content', () => {
    const doc = { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'before ' }, { type: 'text', text: 'bold', marks: [{ type: 'bold' }] }, { type: 'text', text: ' after' }] }] }
    assert.equal(richTextPlainText(doc), 'before bold after')
    assert.equal(hasRichTextContent(emptyRichTextDocument()), false)
    assert.equal(hasRichTextContent({ type: 'doc', content: [{ type: 'image', attrs: { src: '/map.png' } }] }), true)
})

test('unsafe links and image sources are rejected before insertion', () => {
    for (const url of ['javascript:alert(1)', 'data:image/svg+xml,bad', '//evil.test', '/\\evil.test', 'https://example.com/a b']) assert.equal(safeEditorUrl(url, true), false)
    assert.equal(safeEditorUrl('/resource-assets/image', true), true)
    assert.equal(safeEditorUrl('https://example.com/map.gif', true), true)
})
