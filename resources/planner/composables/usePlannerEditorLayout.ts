import { onBeforeUnmount, ref } from 'vue'

export type PlannerResizableRegion = 'left' | 'right' | 'timeline'

const SIDE_PANEL_MIN = 176
const SIDE_PANEL_MAX = 420
const TIMELINE_MIN = 112
const TIMELINE_MAX = 360

const clamp = (value: number, minimum: number, maximum: number): number =>
	Math.min(Math.max(value, minimum), maximum)

export function usePlannerEditorLayout() {
	const leftPanelWidth = ref(240)
	const rightPanelWidth = ref(272)
	const timelineHeight = ref(192)

	let stopActiveResize: (() => void) | null = null

	const sizeFor = (region: PlannerResizableRegion): number => {
		if (region === 'left') {
			return leftPanelWidth.value
		}

		if (region === 'right') {
			return rightPanelWidth.value
		}

		return timelineHeight.value
	}

	const setSize = (region: PlannerResizableRegion, size: number): void => {
		if (region === 'left') {
			leftPanelWidth.value = clamp(size, SIDE_PANEL_MIN, SIDE_PANEL_MAX)

			return
		}

		if (region === 'right') {
			rightPanelWidth.value = clamp(size, SIDE_PANEL_MIN, SIDE_PANEL_MAX)

			return
		}

		timelineHeight.value = clamp(size, TIMELINE_MIN, TIMELINE_MAX)
	}

	const adjustSize = (region: PlannerResizableRegion, delta: number): void => {
		setSize(region, sizeFor(region) + delta)
	}

	const startResize = (
		region: PlannerResizableRegion,
		event: PointerEvent,
	): void => {
		stopActiveResize?.()

		const startX = event.clientX
		const startY = event.clientY
		const startSize = sizeFor(region)
		const previousCursor = document.body.style.cursor
		const previousUserSelect = document.body.style.userSelect

		document.body.style.cursor = region === 'timeline' ? 'row-resize' : 'col-resize'
		document.body.style.userSelect = 'none'

		const handlePointerMove = (moveEvent: PointerEvent): void => {
			if (region === 'left') {
				setSize(region, startSize + moveEvent.clientX - startX)
			} else if (region === 'right') {
				setSize(region, startSize - (moveEvent.clientX - startX))
			} else {
				setSize(region, startSize - (moveEvent.clientY - startY))
			}
		}

		const stopResize = (): void => {
			window.removeEventListener('pointermove', handlePointerMove)
			window.removeEventListener('pointerup', stopResize)
			window.removeEventListener('pointercancel', stopResize)
			document.body.style.cursor = previousCursor
			document.body.style.userSelect = previousUserSelect
			stopActiveResize = null
		}

		stopActiveResize = stopResize
		window.addEventListener('pointermove', handlePointerMove)
		window.addEventListener('pointerup', stopResize)
		window.addEventListener('pointercancel', stopResize)
	}

	onBeforeUnmount(() => {
		stopActiveResize?.()
	})

	return {
		leftPanelWidth,
		rightPanelWidth,
		timelineHeight,
		adjustSize,
		startResize,
	}
}
