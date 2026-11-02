import assert from 'node:assert/strict'
import test from 'node:test'
import { JSDOM } from 'jsdom'
import { initializeScrollbars } from '../../resources/js/utils/browserScrollbars.ts'

function setup(t) {
    const dom = new JSDOM('<main><section></section></main>')
    const restore = []
    for (const name of ['window', 'document', 'Element']) {
        const previous = Object.getOwnPropertyDescriptor(globalThis, name)
        Object.defineProperty(globalThis, name, { value: dom.window[name], configurable: true })
        restore.push(() => previous ? Object.defineProperty(globalThis, name, previous) : delete globalThis[name])
    }
    t.mock.timers.enable({ apis: ['setTimeout'] })
    const stop = initializeScrollbars()
    t.after(() => { stop(); dom.window.close(); restore.forEach(reset => reset()) })
    const root = document.documentElement
    const panel = document.querySelector('section')
    const scroll = target => target.dispatchEvent(new window.Event('scroll'))
    return { root, panel, scroll, stop }
}

test('nested and newly mounted scrollers get independent activity windows', t => {
    const { root, panel, scroll } = setup(t)
    const dialog = document.body.appendChild(document.createElement('div'))
    scroll(panel)
    t.mock.timers.tick(600)
    scroll(dialog)
    t.mock.timers.tick(300)
    assert.equal(panel.hasAttribute('data-scrollbar-scrolling'), false)
    assert.equal(dialog.hasAttribute('data-scrollbar-scrolling'), true)
    assert.equal(root.hasAttribute('data-scrollbar-scrolling'), false)
    t.mock.timers.tick(600)
    assert.equal(dialog.hasAttribute('data-scrollbar-scrolling'), false)
})

test('continued scrolling extends visibility and viewport scrolls target the root', t => {
    const { root, panel, scroll } = setup(t)
    scroll(panel)
    t.mock.timers.tick(700)
    scroll(panel)
    scroll(document)
    t.mock.timers.tick(700)
    assert.equal(panel.hasAttribute('data-scrollbar-scrolling'), true)
    assert.equal(root.hasAttribute('data-scrollbar-scrolling'), true)
    t.mock.timers.tick(200)
    assert.equal(panel.hasAttribute('data-scrollbar-scrolling'), false)
    assert.equal(root.hasAttribute('data-scrollbar-scrolling'), false)
})

test('leaving the page and losing focus suppresses hovered thumbs until interaction returns', t => {
    const { root } = setup(t)
    root.dispatchEvent(new window.Event('pointerleave'))
    assert.equal(root.hasAttribute('data-scrollbar-inactive'), true)
    root.dispatchEvent(new window.Event('pointerenter'))
    assert.equal(root.hasAttribute('data-scrollbar-inactive'), false)
    window.dispatchEvent(new window.Event('blur'))
    assert.equal(root.hasAttribute('data-scrollbar-inactive'), true)
    window.dispatchEvent(new window.Event('focus'))
    assert.equal(root.hasAttribute('data-scrollbar-inactive'), false)
})

test('unmount removes listeners, timers and temporary attributes', t => {
    const { root, panel, scroll, stop } = setup(t)
    scroll(panel)
    stop()
    assert.equal(root.hasAttribute('data-scrollbar-enhanced'), false)
    assert.equal(panel.hasAttribute('data-scrollbar-scrolling'), false)
    scroll(panel)
    window.dispatchEvent(new window.Event('blur'))
    t.mock.timers.tick(1000)
    assert.equal(panel.hasAttribute('data-scrollbar-scrolling'), false)
    assert.equal(root.hasAttribute('data-scrollbar-inactive'), false)
})
