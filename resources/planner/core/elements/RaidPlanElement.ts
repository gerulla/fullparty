import {
	Container,
	type FederatedPointerEvent,
} from 'pixi.js'
import {
	PLANNER_STAGE_HEIGHT,
	PLANNER_STAGE_WIDTH,
} from '../rendering/PlannerStage'
import type { RaidPlanElementComponent } from '../../types/RaidPlan'

export interface RaidPlanElementOptions<T extends RaidPlanElementComponent> {
	data: T
	selected: boolean
	editable: boolean
	onSelect: (elementId: string) => void
	onContextMenu?: (elementId: string, clientX: number, clientY: number) => void
	onChange: (elementId: string, changes: Partial<T>) => void
	onTransformStart?: () => void
	onTransformEnd?: () => void
	requestRender?: () => void
}

export abstract class RaidPlanElement<
	T extends RaidPlanElementComponent,
> extends Container {
	private draggingPointerId: number | null = null
	private dragOffsetX = 0
	private dragOffsetY = 0

	protected constructor(
		protected readonly options: RaidPlanElementOptions<T>,
	) {
		super()

		this.position.set(
			PLANNER_STAGE_WIDTH / 2 + options.data.offset_x,
			PLANNER_STAGE_HEIGHT / 2 + options.data.offset_y,
		)
		this.rotation = options.data.rotation * (Math.PI / 180)
		this.eventMode = 'static'
		this.cursor = options.editable ? 'move' : 'pointer'

		this.on('pointerdown', this.startDrag)
		this.on('rightclick', this.openContextMenu)
		this.on('globalpointermove', this.moveDrag)
		this.on('pointerup', this.stopDrag)
		this.on('pointerupoutside', this.stopDrag)
	}

	private readonly openContextMenu = (event: FederatedPointerEvent): void => {
		event.stopPropagation()
		event.nativeEvent.preventDefault()
		this.options.onSelect(this.options.data.id)
		this.options.onContextMenu?.(
			this.options.data.id,
			event.clientX,
			event.clientY,
		)
	}

	private readonly startDrag = (event: FederatedPointerEvent): void => {
		event.stopPropagation()
		event.nativeEvent.stopPropagation()
		this.options.onSelect(this.options.data.id)

		if (!this.options.editable || event.button !== 0 || !this.parent) {
			return
		}

		const pointer = event.getLocalPosition(this.parent)

		this.draggingPointerId = event.pointerId
		this.dragOffsetX = pointer.x - this.x
		this.dragOffsetY = pointer.y - this.y
		this.cursor = 'grabbing'
		this.options.onTransformStart?.()
	}

	private readonly moveDrag = (event: FederatedPointerEvent): void => {
		if (
			this.draggingPointerId !== event.pointerId
			|| !this.parent
		) {
			return
		}

		event.stopPropagation()
		event.nativeEvent.stopPropagation()

		const pointer = event.getLocalPosition(this.parent)

		this.position.set(
			this.clamp(pointer.x - this.dragOffsetX, 0, PLANNER_STAGE_WIDTH),
			this.clamp(pointer.y - this.dragOffsetY, 0, PLANNER_STAGE_HEIGHT),
		)
		this.emitChange({
			offset_x: Math.round(this.x - PLANNER_STAGE_WIDTH / 2),
			offset_y: Math.round(this.y - PLANNER_STAGE_HEIGHT / 2),
		} as Partial<T>)
		this.requestRender()
	}

	private readonly stopDrag = (event: FederatedPointerEvent): void => {
		if (this.draggingPointerId !== event.pointerId) {
			return
		}

		event.stopPropagation()
		event.nativeEvent.stopPropagation()
		this.draggingPointerId = null
		this.cursor = this.options.editable ? 'move' : 'pointer'
		this.options.onTransformEnd?.()
	}

	protected emitChange(changes: Partial<T>): void {
		this.options.onChange(this.options.data.id, changes)
	}

	protected requestRender(): void {
		this.options.requestRender?.()
	}

	protected selectElement(): void {
		this.options.onSelect(this.options.data.id)
	}

	protected startTransform(): void {
		this.options.onTransformStart?.()
	}

	protected endTransform(): void {
		this.options.onTransformEnd?.()
	}

	protected stopPointerEvent(event: FederatedPointerEvent): void {
		event.stopPropagation()
		event.nativeEvent.stopPropagation()
	}

	protected clamp(value: number, minimum: number, maximum: number): number {
		return Math.min(maximum, Math.max(minimum, value))
	}
}
