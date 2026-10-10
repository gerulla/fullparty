import { computed, onScopeDispose, ref, watch, type Ref } from 'vue'
import { useResizeObserver } from '@vueuse/core'

export function useImageViewerZoom(viewport: Ref<HTMLElement | null>, image: Ref<HTMLImageElement | null>) {
    const minZoom = 100
    const maxZoom = 500
    const zoom = ref(minZoom)
    const offset = ref({ x: 0, y: 0 })
    const viewportSize = ref({ width: 0, height: 0 })
    const naturalSize = ref({ width: 0, height: 0 })
    const dragging = ref(false)
    let drag: { pointerId: number; x: number; y: number; target: HTMLElement } | null = null

    const ready = computed(() => naturalSize.value.width > 0 && naturalSize.value.height > 0 && viewportSize.value.width > 0 && viewportSize.value.height > 0)
    const fittedSize = computed(() => {
        if (!ready.value) return { width: 0, height: 0 }
        const ratio = Math.min(1, viewportSize.value.width / naturalSize.value.width, viewportSize.value.height / naturalSize.value.height)
        return { width: naturalSize.value.width * ratio, height: naturalSize.value.height * ratio }
    })
    const bounds = computed(() => ({
        x: Math.max(0, (fittedSize.value.width * zoom.value / 100 - viewportSize.value.width) / 2),
        y: Math.max(0, (fittedSize.value.height * zoom.value / 100 - viewportSize.value.height) / 2),
    }))
    const canPan = computed(() => bounds.value.x > 0 || bounds.value.y > 0)
    const imageStyle = computed(() => ({
        width: `${fittedSize.value.width}px`,
        height: `${fittedSize.value.height}px`,
        transform: `translate(${offset.value.x}px, ${offset.value.y}px) scale(${zoom.value / 100})`,
    }))

    function panTo(x: number, y: number) {
        offset.value = {
            x: Math.max(-bounds.value.x, Math.min(bounds.value.x, x)),
            y: Math.max(-bounds.value.y, Math.min(bounds.value.y, y)),
        }
    }

    function stopDragging(event?: PointerEvent) {
        if (event && drag?.pointerId !== event.pointerId) return
        const previous = drag
        drag = null
        dragging.value = false
        if (previous?.target.hasPointerCapture(previous.pointerId)) previous.target.releasePointerCapture(previous.pointerId)
    }

    function reset() {
        stopDragging()
        zoom.value = minZoom
        offset.value = { x: 0, y: 0 }
    }

    function measure() {
        viewportSize.value = { width: viewport.value?.clientWidth ?? 0, height: viewport.value?.clientHeight ?? 0 }
        panTo(offset.value.x, offset.value.y)
    }

    function imageLoaded() {
        naturalSize.value = { width: image.value?.naturalWidth ?? 0, height: image.value?.naturalHeight ?? 0 }
        reset()
        measure()
    }

    function setZoom(value: number, anchor = { x: 0, y: 0 }) {
        if (!ready.value || !Number.isFinite(value)) return
        const next = Math.max(minZoom, Math.min(maxZoom, Math.round(value)))
        const ratio = next / zoom.value
        zoom.value = next
        // Keep the pixel beneath the pointer in place while zooming.
        panTo(anchor.x - (anchor.x - offset.value.x) * ratio, anchor.y - (anchor.y - offset.value.y) * ratio)
        if (!canPan.value) stopDragging()
    }

    function onWheel(event: WheelEvent) {
        if (!viewport.value || !event.deltaY) return
        const rect = viewport.value.getBoundingClientRect()
        const delta = event.deltaY * (event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? rect.height : 1)
        setZoom(zoom.value * Math.exp(-Math.max(-100, Math.min(100, delta)) * 0.002), {
            x: event.clientX - rect.left - rect.width / 2,
            y: event.clientY - rect.top - rect.height / 2,
        })
    }

    function onPointerDown(event: PointerEvent) {
        if (!canPan.value || event.button !== 0 || !event.isPrimary || drag || !viewport.value) return
        event.preventDefault()
        viewport.value.focus({ preventScroll: true })
        viewport.value.setPointerCapture(event.pointerId)
        drag = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, target: viewport.value }
        dragging.value = true
    }

    function onPointerMove(event: PointerEvent) {
        if (!drag || drag.pointerId !== event.pointerId) return
        panTo(offset.value.x + event.clientX - drag.x, offset.value.y + event.clientY - drag.y)
        drag.x = event.clientX
        drag.y = event.clientY
    }

    function onKeydown(event: KeyboardEvent) {
        if (event.ctrlKey || event.metaKey || event.altKey) return
        if (event.key === '+' || event.key === '=') setZoom(zoom.value + 50)
        else if (event.key === '-') setZoom(zoom.value - 50)
        else if (event.key === '0' || event.key === 'Home') reset()
        else if (canPan.value && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
            const step = event.shiftKey ? 100 : 40
            panTo(offset.value.x + (event.key === 'ArrowLeft' ? step : event.key === 'ArrowRight' ? -step : 0),
                offset.value.y + (event.key === 'ArrowUp' ? step : event.key === 'ArrowDown' ? -step : 0))
        } else return
        event.preventDefault()
    }

    useResizeObserver(viewport, measure)
    watch(image, element => { if (element?.complete) imageLoaded() }, { flush: 'post' })
    onScopeDispose(stopDragging)

    return { zoom, minZoom, maxZoom, ready, dragging, canPan, imageStyle, reset, imageLoaded, setZoom, onWheel, onPointerDown, onPointerMove, stopDragging, onKeydown }
}
