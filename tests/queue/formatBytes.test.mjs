import assert from 'node:assert/strict'
import test from 'node:test'
import { formatBytes } from '../../resources/js/utils/formatBytes.ts'

test('small uploads retain visible non-zero byte and kilobyte sizes', () => {
    for (const [bytes, expected] of [[0, '0 B'], [1, '1 B'], [512, '512 B'], [1023, '1,023 B'], [1024, '1 KB'], [1536, '1.5 KB'], [4096, '4 KB']]) {
        assert.equal(formatBytes(bytes), expected)
    }
})

test('file sizes scale through gigabytes without unnecessary decimal zeros', () => {
    assert.equal(formatBytes(1024 ** 2), '1 MB')
    assert.equal(formatBytes(2.5 * 1024 ** 2), '2.5 MB')
    assert.equal(formatBytes(1024 ** 3), '1 GB')
    assert.equal(formatBytes(1.25 * 1024 ** 3), '1.25 GB')
    assert.equal(formatBytes(1024 ** 4), '1,024 GB')
})

test('rounding near a unit boundary promotes to the next supported unit', () => {
    assert.equal(formatBytes(1024 ** 2 - 1), '1 MB')
    assert.equal(formatBytes(1024 ** 3 - 1), '1 GB')
    assert.equal(formatBytes(12345), '12.06 KB')
})

test('storage usage and quota choose units independently and respect locale number formatting', () => {
    assert.equal(`${formatBytes(2048)} / ${formatBytes(1024 ** 3)}`, '2 KB / 1 GB')
    assert.equal(formatBytes(1536, 'de'), '1,5 KB')
    assert.equal(formatBytes(1536, 'fr'), '1,5 KB')
    assert.equal(formatBytes(1536, 'ja'), '1.5 KB')
})

test('invalid size data is not presented as empty storage', () => {
    for (const bytes of [-1, NaN, Infinity, -Infinity]) assert.equal(formatBytes(bytes), '-')
})
