import {
	computed,
	onBeforeUnmount,
	onMounted,
	ref,
	toValue,
	watch,
	type MaybeRefOrGetter,
	type Ref,
} from 'vue'
import {
	PLANNER_STAGE_HEIGHT,
	PLANNER_STAGE_WIDTH,
} from '../core/rendering/PlannerStage'

const VIEWPORT_PADDING = 40

export type PlannerZoom = number | 'fit' | 'fill'

const clamp = (value: number, minimum: number, maximum: number): number =>
	Math.min(Math.max(value, minimum), maximum)

export function usePlannerCanvasViewport(
	viewport: Ref<HTMLElement | null>,
	zoom: MaybeRefOrGetter<PlannerZoom>,
) {
	const viewportWidth = ref(0)
	const viewportHeight = ref(0)
	const panX = ref(0)
	const panY = ref(0)
	const isPanning = ref(false)

	let resizeObserver: ResizeObserver | null = null
	let pointerId: number | null = null
	let lastPointerX = 0
	let lastPointerY = 0

	const displayScale = computed(() => {
		if (viewportWidth.value === 0 || viewportHeight.value === 0) {
			return 1
		}

		const zoomValue = toValue(zoom)

		if (typeof zoomValue === 'number') {
			return zoomValue / 100
		}

		if (zoomValue === 'fill') {
			return Math.max(
				viewportWidth.value / PLANNER_STAGE_WIDTH,
				viewportHeight.value / PLANNER_STAGE_HEIGHT,
			)
		}

		return Math.min(
			Math.max(0, viewportWidth.value - VIEWPORT_PADDING) / PLANNER_STAGE_WIDTH,
			Math.max(0, viewportHeight.value - VIEWPORT_PADDING) / PLANNER_STAGE_HEIGHT,
			1,
		)
	})

	const displayWidth = computed(() => PLANNER_STAGE_WIDTH * displayScale.value)
	const displayHeight = computed(() => PLANNER_STAGE_HEIGHT * displayScale.value)
	const canPan = computed(
		() =>
			displayWidth.value > viewportWidth.value ||
			displayHeight.value > viewportHeight.value,
	)

	const clampPan = (): void => {
		if (!canPan.value) {
			panX.value = 0
			panY.value = 0

			return
		}

		const maximumX = Math.max(0, (displayWidth.value - viewportWidth.value) / 2)
		const maximumY = Math.max(0, (displayHeight.value - viewportHeight.value) / 2)

		panX.value = clamp(panX.value, -maximumX, maximumX)
		panY.value = clamp(panY.value, -maximumY, maximumY)
	}

	const updateViewportSize = (): void => {
		const element = viewport.value

		if (!element) {
			return
		}

		viewportWidth.value = element.clientWidth
		viewportHeight.value = element.clientHeight
		clampPan()
	}

	const startPan = (event: PointerEvent): void => {
		const element = viewport.value

		if (!element || !canPan.value || event.button !== 0) {
			return
		}

		pointerId = event.pointerId
		lastPointerX = event.clientX
		lastPointerY = event.clientY
		isPanning.value = true
		element.setPointerCapture(event.pointerId)
	}

	const movePan = (event: PointerEvent): void => {
		if (!isPanning.value || pointerId !== event.pointerId) {
			return
		}

		panX.value += event.clientX - lastPointerX
		panY.value += event.clientY - lastPointerY
		lastPointerX = event.clientX
		lastPointerY = event.clientY
		clampPan()
	}

	const stopPan = (event: PointerEvent): void => {
		const element = viewport.value

		if (!isPanning.value || pointerId !== event.pointerId) {
			return
		}

		if (element?.hasPointerCapture(event.pointerId)) {
			element.releasePointerCapture(event.pointerId)
		}

		pointerId = null
		isPanning.value = false
	}

	const stageStyle = computed(() => ({
		width: `${displayWidth.value}px`,
		height: `${displayHeight.value}px`,
		transform: `translate(calc(-50% + ${panX.value}px), calc(-50% + ${panY.value}px))`,
	}))

	const viewportCursor = computed(() => {
		if (!canPan.value) {
			return 'cursor-default'
		}

		return isPanning.value ? 'cursor-grabbing' : 'cursor-grab'
	})

	watch(
		() => toValue(zoom),
		() => clampPan(),
	)

	onMounted(() => {
		updateViewportSize()
		resizeObserver = new ResizeObserver(updateViewportSize)

		if (viewport.value) {
			resizeObserver.observe(viewport.value)
		}
	})

	onBeforeUnmount(() => {
		resizeObserver?.disconnect()
	})

	return {
		stageStyle,
		viewportCursor,
		startPan,
		movePan,
		stopPan,
	}
}
