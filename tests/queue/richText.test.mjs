import assert from 'node:assert/strict'
import test from 'node:test'
import { spawnSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { JSDOM } from 'jsdom'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { richTextExtensions } from '../../resources/js/utils/richTextExtensions.ts'
import { emptyRichTextDocument, hasRichTextContent, richTextPlainText, safeEditorUrl } from '../../resources/js/utils/richText.ts'

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://fullparty.test' })
for (const key of ['window', 'document', 'navigator', 'Node', 'HTMLElement', 'Element', 'MutationObserver', 'DOMParser', 'getComputedStyle']) {
    Object.defineProperty(globalThis, key, { configurable: true, value: key === 'getComputedStyle' ? dom.window.getComputedStyle.bind(dom.window) : dom.window[key] })
}
globalThis.requestAnimationFrame = callback => setTimeout(callback, 0)
globalThis.cancelAnimationFrame = clearTimeout
const createEditor = (content, options = {}) => new Editor({ element: document.createElement('div'), extensions: [StarterKit, ...richTextExtensions()], content: content ?? emptyRichTextDocument(), enableContentCheck: true, ...options })
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
    assert.equal(hasRichTextContent({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'inlineImage', attrs: { src: '/map.png' } }] }] }), true)
})

const mapImage = { type: 'image', attrs: { src: '/resource-assets/11111111-1111-1111-1111-111111111111', alt: 'Bridge map', width: 640, height: 320 } }
function selectImage(editor, type = 'image') {
    let position
    editor.state.doc.descendants((node, pos) => { if (position === undefined && node.type.name === type) position = pos })
    assert.notEqual(position, undefined)
    editor.commands.setNodeSelection(position)
}

test('image widths, wrapping and alignment survive saving and HTML reloads', () => {
    const editor = createEditor({ type: 'doc', content: [mapImage, ...textDocument('Stand here').content] })
    selectImage(editor)
    assert.equal(editor.commands.setRichTextImageWidth(240), true)
    assert.equal(editor.state.selection.node.attrs.height, null)
    assert.equal(editor.commands.setRichTextImageWidth(0), false)
    assert.equal(editor.commands.setRichTextImageWidth(100.5), false)
    assert.equal(editor.commands.setRichTextImageLayout('wrap-right'), true)
    let saved = backendDocument(editor.getJSON())
    assert.match(saved.html, /data-image-layout="wrap-right"/)
    assert.match(saved.html, /width="240"/)
    const reloaded = createEditor(saved.html)
    assert.equal(reloaded.getJSON().content[0].attrs.layout, 'wrap-right')
    assert.equal(reloaded.getJSON().content[0].attrs.width, 240)
    editor.commands.setRichTextImageLayout('block')
    editor.commands.setRichTextImageAlign('center')
    saved = backendDocument(editor.getJSON())
    assert.match(saved.html, /data-image-align="center"/)
    editor.commands.setRichTextImageWidth(null)
    assert.equal(editor.state.selection.node.attrs.width, null)
    assert.equal(editor.state.selection.node.attrs.height, null)
    editor.destroy(); reloaded.destroy()
})

test('block images become true inline images without losing surrounding text or saved dimensions', () => {
    for (const content of [[mapImage], [mapImage, ...textDocument('After').content], [...textDocument('Before').content, mapImage, ...textDocument('After').content]]) {
        const editor = createEditor({ type: 'doc', content })
        const beforeText = richTextPlainText(editor.getJSON()).replaceAll('\n', '')
        selectImage(editor)
        assert.equal(editor.commands.setRichTextImageLayout('inline'), true)
        editor.state.doc.check()
        assert.equal(editor.state.selection.node.type.name, 'inlineImage')
        assert.equal(editor.commands.setRichTextImageWidth(80), true)
        const saved = backendDocument(editor.getJSON())
        const reloaded = createEditor(saved.html)
        reloaded.state.doc.check()
        selectImage(reloaded, 'inlineImage')
        assert.equal(reloaded.state.selection.node.attrs.width, 80)
        assert.match(saved.html, /<p>.*data-inline-image/s)
        assert.equal(editor.commands.setRichTextImageLayout('wrap-left'), true)
        editor.state.doc.check()
        assert.equal(editor.state.selection.node.type.name, 'image')
        assert.equal(editor.state.selection.node.attrs.layout, 'wrap-left')
        assert.equal(richTextPlainText(editor.getJSON()).replaceAll('\n', ''), beforeText)
        editor.destroy(); reloaded.destroy()
    }
})

test('inline images can be extracted from text, headings, lists and tables with valid structure', () => {
    const inline = { ...mapImage, type: 'inlineImage' }
    for (const surrounding of [false, true]) {
        const paragraph = { type: 'paragraph', content: [...(surrounding ? [{ type: 'text', text: 'Before ' }] : []), inline, { type: 'text', text: ' after' }] }
        for (const content of [[paragraph], [{ ...paragraph, type: 'heading', attrs: { level: 2 } }], [{ type: 'bulletList', content: [{ type: 'listItem', content: [paragraph] }] }], [{ type: 'taskList', content: [{ type: 'taskItem', attrs: { checked: false }, content: [paragraph] }] }], [{ type: 'table', content: [{ type: 'tableRow', content: [{ type: 'tableCell', content: [paragraph] }] }] }]]) {
            const editor = createEditor({ type: 'doc', content })
            selectImage(editor, 'inlineImage')
            const original = editor.getJSON()
            assert.equal(editor.commands.setRichTextImageLayout('block'), true)
            editor.state.doc.check()
            backendDocument(editor.getJSON())
            assert.equal(editor.commands.undo(), true)
            assert.deepEqual(editor.getJSON(), original)
            editor.destroy()
        }
    }
})

test('resizable image views update their actual displayed width and layout after toolbar commands', () => {
    const editor = createEditor({ type: 'doc', content: [mapImage] }, { extensions: [StarterKit, ...richTextExtensions({ resizableImages: true })] })
    selectImage(editor)
    const wrapper = editor.view.dom.querySelector('[data-resize-container]')
    const image = wrapper.querySelector('img')
    assert.equal(image.style.width, '640px')
    editor.commands.setRichTextImageWidth(200)
    assert.equal(image.style.width, '200px')
    assert.equal(image.style.height, 'auto')
    editor.commands.setRichTextImageLayout('wrap-left')
    assert.equal(wrapper.dataset.imageLayout, 'wrap-left')
    editor.commands.setRichTextImageWidth(null)
    assert.equal(image.style.width, '')
    editor.commands.setRichTextImageLayout('inline')
    assert.equal(editor.view.dom.querySelector('[data-resize-container]'), null)
    assert.ok(editor.view.dom.querySelector('p img[data-inline-image]'))
    editor.destroy()
})

test('adjacent images become one centered group with independent sizes and survive backend and HTML reloads', () => {
    const second = { type: 'image', attrs: { src: '/second.png', width: 180, layout: 'wrap-right' } }
    const editor = createEditor({ type: 'doc', content: [...textDocument('Before').content, mapImage, second, ...textDocument('After').content, mapImage] })
    selectImage(editor)
    const original = editor.getJSON()
    assert.equal(editor.can().createImageGroup(), true)
    assert.deepEqual(editor.getJSON(), original)
    assert.equal(editor.commands.createImageGroup(), true)
    assert.deepEqual(editor.getJSON().content.map(node => node.type), ['paragraph', 'imageGroup', 'paragraph', 'image', 'paragraph'])
    let group = editor.getJSON().content[1]
    assert.equal(group.attrs.align, 'center')
    assert.equal(group.content.length, 2)
    assert.ok(group.content.every(image => image.attrs.layout === 'block'))
    assert.equal(editor.commands.createImageGroup(), false)
    assert.equal(editor.commands.setRichTextImageLayout('inline'), false)
    assert.equal(editor.commands.setRichTextImageWidth(240), true)
    assert.equal(editor.commands.setImageGroupAlign('right'), true)
    assert.equal(editor.commands.setImageGroupAlign('justify'), false)
    const saved = backendDocument(editor.getJSON())
    for (const content of [saved.document, saved.html]) {
        const reloaded = createEditor(content)
        reloaded.state.doc.check()
        group = reloaded.getJSON().content[1]
        assert.equal(group.type, 'imageGroup')
        assert.equal(group.attrs.align, 'right')
        assert.deepEqual(group.content.map(image => image.attrs.width), [240, 180])
        assert.equal(reloaded.getText(), editor.getText())
        reloaded.destroy()
    }
    editor.destroy()
})

test('groups accept more images and ungroup without replacing existing images or losing their order', () => {
    const editor = createEditor({ type: 'doc', content: [mapImage] })
    selectImage(editor)
    editor.commands.createImageGroup()
    assert.equal(editor.commands.addImageToGroup({ src: '/second.png', alt: 'Second' }), true)
    assert.equal(editor.commands.addImageToGroup({ src: '/third.png', alt: 'Third' }), true)
    assert.equal(editor.getJSON().content[0].content.length, 3)
    assert.equal(editor.state.selection.node.attrs.src, '/third.png')
    editor.commands.setImageGroupAlign('right')
    assert.equal(editor.commands.ungroupImages(), true)
    editor.state.doc.check()
    const images = editor.getJSON().content.filter(node => node.type === 'image')
    assert.deepEqual(images.map(node => node.attrs.src), [mapImage.attrs.src, '/second.png', '/third.png'])
    assert.ok(images.every(node => node.attrs.align === 'right' && node.attrs.width === 640))
    assert.equal(editor.commands.addImageToGroup({ src: '/outside.png' }), false)
    editor.destroy()
})

test('grouping supports undo, list items and table cells without damaging surrounding content', () => {
    for (const content of [
        [...textDocument('Before').content, mapImage, mapImage],
        [{ type: 'bulletList', content: [{ type: 'listItem', content: [...textDocument('Before').content, mapImage, mapImage] }] }],
        [{ type: 'table', content: [{ type: 'tableRow', content: [{ type: 'tableCell', content: [mapImage, mapImage] }] }] }],
    ]) {
        const editor = createEditor({ type: 'doc', content })
        selectImage(editor)
        const original = editor.getJSON()
        assert.equal(editor.commands.createImageGroup(), true)
        editor.state.doc.check()
        backendDocument(editor.getJSON())
        assert.equal(editor.commands.undo(), true)
        assert.deepEqual(editor.getJSON(), original)
        editor.destroy()
    }
})

test('deleting grouped images leaves no empty image and Enter continues below the row', () => {
    const editor = createEditor({ type: 'doc', content: [mapImage, mapImage, ...textDocument('After').content] })
    selectImage(editor)
    editor.commands.createImageGroup()
    assert.equal(editor.commands.deleteRichTextImage(), true)
    assert.equal(editor.getJSON().content[0].content.length, 1)
    selectImage(editor)
    editor.view.dom.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    assert.deepEqual(editor.getJSON().content.map(node => node.type), ['imageGroup', 'paragraph', 'paragraph'])
    editor.commands.insertContent('Below')
    assert.equal(editor.getJSON().content[1].content[0].text, 'Below')
    selectImage(editor)
    editor.view.dom.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Backspace', bubbles: true }))
    editor.state.doc.check()
    assert.equal(editor.getJSON().content.some(node => node.type === 'imageGroup'), false)
    assert.equal(editor.getText(), 'Below\n\nAfter')
    backendDocument(editor.getJSON())
    editor.destroy()
})

test('unsafe links and image sources are rejected before insertion', () => {
    for (const url of ['javascript:alert(1)', 'data:image/svg+xml,bad', '//evil.test', '/\\evil.test', 'https://example.com/a b']) assert.equal(safeEditorUrl(url, true), false)
    assert.equal(safeEditorUrl('/resource-assets/image', true), true)
    assert.equal(safeEditorUrl('https://example.com/map.gif', true), true)
})

test('cutting the last grouped image removes its group without creating a broken placeholder image', () => {
    const editor = createEditor({ type: 'doc', content: [mapImage, ...textDocument('Keep this article').content] })
    selectImage(editor)
    editor.commands.createImageGroup()
    editor.commands.deleteSelection()
    editor.state.doc.check()
    assert.equal(editor.getJSON().content.some(node => node.type === 'imageGroup' || node.type === 'image'), false)
    assert.equal(editor.getText(), 'Keep this article')
    backendDocument(editor.getJSON())
    editor.destroy()
})
