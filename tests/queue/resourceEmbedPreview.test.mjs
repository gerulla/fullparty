import test from 'node:test'
import assert from 'node:assert/strict'
import { resourceEmbedCharacterCount, resourceEmbedFieldRows, resourceEmbedImage, resourceEmbedLink } from '../../resources/js/utils/resourceEmbedPreview.ts'

const field = (name, inline = true) => ({ name, value: 'Content', inline })
const names = rows => rows.map(row => row.map(item => item.name))

test('embed character counts include the fixed footer and all fields using Unicode characters', () => {
    const embed = { title: 'Plan', description: '\u{1f31f}', author: 'Host', fields: [{ name: 'Side', value: 'West', inline: true }], url: 'https://fullparty.gg' }
    assert.equal(resourceEmbedCharacterCount(embed), 9 + 4 + 1 + 4 + 4 + 4)
    assert.equal(resourceEmbedCharacterCount({ title: '', description: 'x'.repeat(5991), author: '', fields: [] }), 6000)
})

test('embed links only allow absolute HTTP and HTTPS URLs', () => {
    assert.equal(resourceEmbedLink(' https://fullparty.gg/guide '), 'https://fullparty.gg/guide')
    assert.equal(resourceEmbedLink('http://example.com'), 'http://example.com/')
    for (const value of ['javascript:alert(1)', 'data:text/html,test', '/guide', 'not a URL', '']) {
        assert.equal(resourceEmbedLink(value), undefined)
    }
})

test('embed images support local mock images but reject unsafe sources', () => {
    assert.equal(resourceEmbedImage('/characters/char1.png'), '/characters/char1.png')
    assert.equal(resourceEmbedImage('data:image/png;base64,abc'), 'data:image/png;base64,abc')
    for (const value of ['javascript:alert(1)', 'data:image/svg+xml;base64,abc', '//example.com/image.png', '/\\example.com/image.png']) {
        assert.equal(resourceEmbedImage(value), undefined)
    }
})

test('inline fields use up to three columns without a thumbnail', () => {
    assert.deepEqual(names(resourceEmbedFieldRows(['A', 'B', 'C', 'D'].map(name => field(name)), false)), [['A', 'B', 'C'], ['D']])
})

test('a thumbnail reduces inline rows to two columns and block fields start a new row', () => {
    const fields = [field('A'), field('B'), field('C'), field('Notes', false), field('D'), field('E')]
    assert.deepEqual(names(resourceEmbedFieldRows(fields, true)), [['A', 'B'], ['C'], ['Notes'], ['D', 'E']])
})

test('no fields produce no rows', () => {
    assert.deepEqual(resourceEmbedFieldRows([], false), [])
})
