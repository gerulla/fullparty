import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import ts from 'typescript'
import * as vue from 'vue'

const source = readFileSync(new URL('../../resources/js/composables/useImageViewerZoom.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText

function harness(t, naturalWidth = 1600, naturalHeight = 1200) {
    let resize
    const captures = new Set()
    const viewport = vue.shallowRef({
        clientWidth: 800, clientHeight: 600,
        getBoundingClientRect() { return { left: 0, top: 0, width: this.clientWidth, height: this.clientHeight } },
        focus() {},
        setPointerCapture: id => captures.add(id),
        hasPointerCapture: id => captures.has(id),
        releasePointerCapture: id => captures.delete(id),
    })
    const image = vue.shallowRef({ naturalWidth, naturalHeight, complete: true })
    const modules = { vue, '@vueuse/core': { useResizeObserver: (_element, callback) => { resize = callback } } }
    const exports = {}
    new Function('require', 'exports', compiled)(name => { assert.ok(name in modules, name); return modules[name] }, exports)
    const scope = vue.effectScope()
    const api = scope.run(() => exports.useImageViewerZoom(viewport, image))
    t.after(() => scope.stop())
    api.imageLoaded()
    return { api, captures, image, scope, resize(width, height) { viewport.value.clientWidth = width; viewport.value.clientHeight = height; resize() } }
}

function pointer(overrides = {}) {
    return { button: 0, pointerId: 1, isPrimary: true, clientX: 0, clientY: 0, preventDefault() {}, ...overrides }
}

test('fits large images, preserves small image size, and limits zoom to 100–500%', t => {
    const { api } = harness(t)
    assert.equal(api.imageStyle.value.width, '800px')
    assert.equal(api.imageStyle.value.height, '600px')
    assert.equal(api.zoom.value, 100)
    assert.equal(api.canPan.value, false)
    api.setZoom(900)
    assert.equal(api.zoom.value, 500)
    api.setZoom(Number.NaN)
    assert.equal(api.zoom.value, 500)
    api.setZoom(50)
    assert.equal(api.zoom.value, 100)
    assert.equal(harness(t, 400, 200).api.imageStyle.value.width, '400px')
})

test('dragging clamps at image edges and responds immediately when dragging back', t => {
    const { api, captures } = harness(t)
    api.setZoom(200)
    api.onPointerDown(pointer({ clientX: 100, clientY: 100 }))
    assert.equal(api.dragging.value, true)
    assert.ok(captures.has(1))
    api.onPointerMove(pointer({ clientX: 2000, clientY: 2000 }))
    assert.equal(api.imageStyle.value.transform, 'translate(400px, 300px) scale(2)')
    api.onPointerMove(pointer({ clientX: 1990, clientY: 1990 }))
    assert.equal(api.imageStyle.value.transform, 'translate(390px, 290px) scale(2)')
    api.onPointerMove(pointer({ pointerId: 2, clientX: -2000, clientY: -2000 }))
    assert.equal(api.imageStyle.value.transform, 'translate(390px, 290px) scale(2)')
    api.stopDragging(pointer())
    assert.equal(api.dragging.value, false)
    assert.equal(captures.size, 0)
})

test('panning only uses overflowing axes and is clamped again after a viewport resize', t => {
    const { api, resize } = harness(t, 600, 1200)
    api.setZoom(200)
    api.onPointerDown(pointer())
    api.onPointerMove(pointer({ clientX: 500, clientY: 500 }))
    assert.equal(api.imageStyle.value.transform, 'translate(0px, 300px) scale(2)')
    resize(300, 200)
    assert.equal(api.imageStyle.value.width, '100px')
    assert.equal(api.imageStyle.value.transform, 'translate(0px, 100px) scale(2)')
})

test('wheel zoom stays anchored to the pixel under the pointer', t => {
    const { api } = harness(t)
    api.onWheel({ deltaY: -100, deltaMode: 0, clientX: 600, clientY: 300 })
    assert.equal(api.zoom.value, 122)
    const x = Number(api.imageStyle.value.transform.match(/translate\(([^p]+)px/)[1])
    assert.ok(Math.abs((200 - x) / 1.22 - 200) < 0.000001)
    for (let index = 0; index < 20; index++) api.onWheel({ deltaY: -10, deltaMode: 1, clientX: 400, clientY: 300 })
    assert.equal(api.zoom.value, 500)
})

test('fit, pointer cancellation, and disposal release pointer capture', t => {
    const { api, captures, scope } = harness(t)
    api.setZoom(300)
    api.onPointerDown(pointer())
    api.onPointerMove(pointer({ clientX: 200, clientY: 100 }))
    api.reset()
    assert.equal(api.zoom.value, 100)
    assert.equal(api.imageStyle.value.transform, 'translate(0px, 0px) scale(1)')
    assert.equal(captures.size, 0)
    api.setZoom(200)
    api.onPointerDown(pointer())
    api.stopDragging(pointer())
    assert.equal(captures.size, 0)
    api.onPointerDown(pointer())
    scope.stop()
    assert.equal(captures.size, 0)
})

test('only primary left-button or touch drags start, and fitting images cannot be dragged', t => {
    const { api } = harness(t)
    api.onPointerDown(pointer())
    assert.equal(api.dragging.value, false)
    api.setZoom(200)
    api.onPointerDown(pointer({ button: 1 }))
    api.onPointerDown(pointer({ button: 2 }))
    api.onPointerDown(pointer({ isPrimary: false }))
    assert.equal(api.dragging.value, false)
    api.onPointerDown(pointer({ pointerType: 'touch' }))
    assert.equal(api.dragging.value, true)
})

test('keyboard users can zoom, pan, and reset without hijacking browser zoom shortcuts', t => {
    const { api } = harness(t)
    let prevented = 0
    const key = (value, overrides = {}) => api.onKeydown({ key: value, preventDefault: () => prevented++, ...overrides })
    key('+', { ctrlKey: true })
    assert.equal(api.zoom.value, 100)
    assert.equal(prevented, 0)
    key('+')
    assert.equal(api.zoom.value, 150)
    key('ArrowRight')
    assert.equal(api.imageStyle.value.transform, 'translate(-40px, 0px) scale(1.5)')
    key('Home')
    assert.equal(api.zoom.value, 100)
    assert.equal(prevented, 3)
})

test('cached images are measured when mounted and new image loads reset the view', async t => {
    const { api, image } = harness(t)
    api.setZoom(500)
    image.value = { naturalWidth: 1200, naturalHeight: 600, complete: true }
    await vue.nextTick()
    assert.equal(api.zoom.value, 100)
    assert.equal(api.imageStyle.value.width, '800px')
    assert.equal(api.imageStyle.value.height, '400px')
})
