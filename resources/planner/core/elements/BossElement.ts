import {
	BlurFilter,
	Container,
	Graphics,
	Rectangle,
	type FederatedPointerEvent,
	type GraphicsContext,
} from 'pixi.js'
import type { RaidPlanBossElementComponent } from '../../types/RaidPlan'
import {
	RaidPlanElement,
	type RaidPlanElementOptions,
} from './RaidPlanElement'
import {
	PLANNER_STAGE_HEIGHT,
	PLANNER_STAGE_WIDTH,
} from '../rendering/PlannerStage'

const BOSS_BASE_SIZE = 140
const BOSS_HITBOX_VIEWBOX_SIZE = 573.68
const BOSS_FACE_BASE_SIZE = 46
const SELECTION_PADDING = 8
const HANDLE_SIZE = 14
const ROTATION_ZONE_SIZE = 24
const ROTATION_ZONE_OFFSET = 18
const GLOW_SCALE = 1.012

type TransformMode = 'scale' | 'rotate'

interface TransformState {
	mode: TransformMode
	pointerId: number
	startDistance: number
	startPointerAngle: number
	startRotation: number
	startScale: number
	startCenterX: number
	startCenterY: number
	oppositeX: number
	oppositeY: number
	scaleAxisX: number
	scaleAxisY: number
	startProjection: number
	startDiagonal: number
}

export class BossElement extends RaidPlanElement<RaidPlanBossElementComponent> {
	private readonly glowGraphic: Graphics
	private readonly strokeGraphic: Graphics
	private readonly graphic: Graphics
	private readonly faceGlowGraphic: Graphics
	private readonly faceStrokeGraphic: Graphics
	private readonly faceGraphic: Graphics
	private readonly outline = new Graphics()
	private readonly handles: Graphics[] = []
	private readonly rotationZones: Container[] = []
	private currentScale: number
	private transform: TransformState | null = null

	public constructor(
		context: GraphicsContext,
		faceContext: GraphicsContext,
		private readonly hitboxOffsetY: number,
		options: RaidPlanElementOptions<RaidPlanBossElementComponent>,
	) {
		super(options)

		const graphicScale = (
			BOSS_BASE_SIZE / BOSS_HITBOX_VIEWBOX_SIZE * options.data.scale
		)
		const faceBounds = faceContext.bounds
		const faceGraphicScale = (
			BOSS_FACE_BASE_SIZE / Math.max(faceBounds.width, faceBounds.height)
			* options.data.scale
		)
		const facePivotX = (faceBounds.minX + faceBounds.maxX) / 2
		const facePivotY = (faceBounds.minY + faceBounds.maxY) / 2
		const glowColor = Number.parseInt(options.data.color.slice(1), 16)

		this.currentScale = options.data.scale
		this.glowGraphic = this.createGraphic(
			context,
			BOSS_HITBOX_VIEWBOX_SIZE / 2,
			BOSS_HITBOX_VIEWBOX_SIZE / 2,
			graphicScale,
			glowColor,
			0.9,
			5,
			this.hitboxOffsetY * graphicScale,
		)
		this.strokeGraphic = this.createGraphic(
			context,
			BOSS_HITBOX_VIEWBOX_SIZE / 2,
			BOSS_HITBOX_VIEWBOX_SIZE / 2,
			graphicScale * GLOW_SCALE,
			glowColor,
			0.7,
			1.5,
			this.hitboxOffsetY * graphicScale * GLOW_SCALE,
		)
		this.graphic = this.createGraphic(
			context,
			BOSS_HITBOX_VIEWBOX_SIZE / 2,
			BOSS_HITBOX_VIEWBOX_SIZE / 2,
			graphicScale,
			0xffffff,
			1,
			0,
			this.hitboxOffsetY * graphicScale,
		)
		this.faceGlowGraphic = this.createGraphic(
			faceContext,
			facePivotX,
			facePivotY,
			faceGraphicScale,
			glowColor,
			0.9,
			5,
		)
		this.faceStrokeGraphic = this.createGraphic(
			faceContext,
			facePivotX,
			facePivotY,
			faceGraphicScale * GLOW_SCALE,
			glowColor,
			0.7,
			1.5,
		)
		this.faceGraphic = this.createGraphic(
			faceContext,
			facePivotX,
			facePivotY,
			faceGraphicScale,
			0xffffff,
		)

		this.addChild(
			this.glowGraphic,
			this.strokeGraphic,
			this.graphic,
			this.faceGlowGraphic,
			this.faceStrokeGraphic,
			this.faceGraphic,
		)

		if (options.selected) {
			this.createTransformControls()
		}

		this.updateBounds()
		this.on('globalpointermove', this.moveTransform)
		this.on('pointerup', this.stopTransform)
		this.on('pointerupoutside', this.stopTransform)
	}

	private createTransformControls(): void {
		this.outline.eventMode = 'none'
		this.addChild(this.outline)

		for (let index = 0; index < 4; index += 1) {
			const handle = new Graphics()
			handle.eventMode = this.options.editable ? 'static' : 'none'
			handle.cursor = index % 2 === 0 ? 'nwse-resize' : 'nesw-resize'
			handle.on(
				'pointerdown',
				(event: FederatedPointerEvent) => this.beginTransform(event, 'scale', index),
			)
			this.handles.push(handle)
			this.addChild(handle)

			const zone = this.createRotationZone()
			this.rotationZones.push(zone)
			this.addChild(zone)
		}

		this.drawTransformControls()
	}

	private createRotationZone(): Container {
		const zone = new Container()
		const target = new Graphics()
			.rect(
				-ROTATION_ZONE_SIZE / 2,
				-ROTATION_ZONE_SIZE / 2,
				ROTATION_ZONE_SIZE,
				ROTATION_ZONE_SIZE,
			)
			.fill({ color: 0xffffff, alpha: 0.001 })
		const indicator = new Graphics()
			.arc(0, 0, 6, -2.8, 1.5)
			.stroke({ color: 0x000000, width: 4 })
			.arc(0, 0, 6, -2.8, 1.5)
			.stroke({ color: 0xffffff, width: 2 })
			.moveTo(5, 5)
			.lineTo(8, 3)
			.lineTo(8, 7)
			.closePath()
			.fill({ color: 0xffffff })

		indicator.visible = false
		indicator.eventMode = 'none'
		zone.eventMode = this.options.editable ? 'static' : 'none'
		zone.cursor = 'grab'
		zone.addChild(target, indicator)
		zone.on('pointerover', () => {
			indicator.visible = true
			this.requestRender()
		})
		zone.on('pointerout', () => {
			indicator.visible = false
			this.requestRender()
		})
		zone.on('pointerdown', this.startRotate)

		return zone
	}

	private drawTransformControls(): void {
		const halfSize = this.selectionHalfSize()

		this.outline
			.clear()
			.rect(-halfSize, -halfSize, halfSize * 2, halfSize * 2)
			.stroke({ color: 0x000000, width: 3 })
			.rect(-halfSize, -halfSize, halfSize * 2, halfSize * 2)
			.stroke({ color: 0xffffff, width: 1 })

		const corners = [
			[-halfSize, -halfSize],
			[halfSize, -halfSize],
			[halfSize, halfSize],
			[-halfSize, halfSize],
		] as const

		corners.forEach(([x, y], index) => {
			this.handles[index]
				.clear()
				.rect(-HANDLE_SIZE / 2, -HANDLE_SIZE / 2, HANDLE_SIZE, HANDLE_SIZE)
				.fill({ color: 0xffffff })
				.stroke({ color: 0x000000, width: 2 })
			this.handles[index].position.set(x, y)

			const directionX = x < 0 ? -1 : 1
			const directionY = y < 0 ? -1 : 1
			this.rotationZones[index].position.set(
				x + directionX * ROTATION_ZONE_OFFSET,
				y + directionY * ROTATION_ZONE_OFFSET,
			)
		})
	}

	private readonly startRotate = (event: FederatedPointerEvent): void => {
		this.beginTransform(event, 'rotate')
	}

	private beginTransform(
		event: FederatedPointerEvent,
		mode: TransformMode,
		cornerIndex: number | null = null,
	): void {
		this.stopPointerEvent(event)
		this.selectElement()

		if (!this.options.editable || event.button !== 0 || !this.parent) {
			return
		}

		const pointer = event.getLocalPosition(this.parent)
		const scaleOrigin = this.scaleOrigin(cornerIndex, pointer.x, pointer.y)

		this.transform = {
			mode,
			pointerId: event.pointerId,
			startDistance: Math.max(1, Math.hypot(pointer.x - this.x, pointer.y - this.y)),
			startPointerAngle: Math.atan2(pointer.y - this.y, pointer.x - this.x),
			startRotation: this.rotation,
			startScale: this.currentScale,
			startCenterX: this.x,
			startCenterY: this.y,
			...scaleOrigin,
		}
		this.startTransform()
	}

	private readonly moveTransform = (event: FederatedPointerEvent): void => {
		if (
			!this.transform
			|| this.transform.pointerId !== event.pointerId
			|| !this.parent
		) {
			return
		}

		this.stopPointerEvent(event)
		const pointer = event.getLocalPosition(this.parent)

		if (this.transform.mode === 'scale') {
			this.updateScale(event, pointer.x, pointer.y)
		} else {
			const pointerAngle = Math.atan2(pointer.y - this.y, pointer.x - this.x)
			const rotation = this.transform.startRotation
				+ pointerAngle
				- this.transform.startPointerAngle
			let degrees = this.normalizeDegrees(rotation * 180 / Math.PI)

			if (event.shiftKey) {
				degrees = Math.round(degrees / 45) * 45
			}

			this.rotation = degrees * Math.PI / 180
			this.emitChange({
				rotation: Math.round(degrees * 10) / 10,
			})
		}

		this.requestRender()
	}

	private readonly stopTransform = (event: FederatedPointerEvent): void => {
		if (!this.transform || this.transform.pointerId !== event.pointerId) {
			return
		}

		this.stopPointerEvent(event)
		this.transform = null
		this.endTransform()
	}

	private updateScale(
		event: FederatedPointerEvent,
		pointerX: number,
		pointerY: number,
	): void {
		if (!this.transform) {
			return
		}

		let scale: number

		if (event.altKey) {
			const distance = Math.hypot(
				pointerX - this.transform.startCenterX,
				pointerY - this.transform.startCenterY,
			)

			scale = this.clamp(
				this.transform.startScale * distance / this.transform.startDistance,
				0.1,
				5,
			)
			this.position.set(
				this.transform.startCenterX,
				this.transform.startCenterY,
			)
		} else {
			const projection = (
				(pointerX - this.transform.oppositeX) * this.transform.scaleAxisX
				+ (pointerY - this.transform.oppositeY) * this.transform.scaleAxisY
			)
			const diagonal = this.transform.startDiagonal
				+ projection
				- this.transform.startProjection
			const sideLength = diagonal / Math.SQRT2

			scale = this.clamp(
				(sideLength - SELECTION_PADDING * 2) / BOSS_BASE_SIZE,
				0.1,
				5,
			)

			const clampedDiagonal = (
				BOSS_BASE_SIZE * scale + SELECTION_PADDING * 2
			) * Math.SQRT2

			this.position.set(
				this.transform.oppositeX
					+ this.transform.scaleAxisX * clampedDiagonal / 2,
				this.transform.oppositeY
					+ this.transform.scaleAxisY * clampedDiagonal / 2,
			)
		}

		this.currentScale = scale
		this.updateGraphicScales(scale)
		this.updateBounds()
		this.drawTransformControls()
		this.emitChange({
			scale: Math.round(scale * 1000) / 1000,
			offset_x: Math.round(this.x - PLANNER_STAGE_WIDTH / 2),
			offset_y: Math.round(this.y - PLANNER_STAGE_HEIGHT / 2),
		})
	}

	private scaleOrigin(
		cornerIndex: number | null,
		pointerX: number,
		pointerY: number,
	): Pick<
		TransformState,
		| 'oppositeX'
		| 'oppositeY'
		| 'scaleAxisX'
		| 'scaleAxisY'
		| 'startProjection'
		| 'startDiagonal'
	> {
		if (cornerIndex === null) {
			return {
				oppositeX: this.x,
				oppositeY: this.y,
				scaleAxisX: 0,
				scaleAxisY: 0,
				startProjection: 0,
				startDiagonal: 0,
			}
		}

		const directions = [
			[-1, -1],
			[1, -1],
			[1, 1],
			[-1, 1],
		] as const
		const [directionX, directionY] = directions[cornerIndex]
		const halfSize = this.selectionHalfSize()
		const localX = directionX * halfSize
		const localY = directionY * halfSize
		const cosine = Math.cos(this.rotation)
		const sine = Math.sin(this.rotation)
		const cornerOffsetX = localX * cosine - localY * sine
		const cornerOffsetY = localX * sine + localY * cosine
		const cornerDistance = Math.max(1, Math.hypot(cornerOffsetX, cornerOffsetY))
		const scaleAxisX = cornerOffsetX / cornerDistance
		const scaleAxisY = cornerOffsetY / cornerDistance
		const oppositeX = this.x - cornerOffsetX
		const oppositeY = this.y - cornerOffsetY

		return {
			oppositeX,
			oppositeY,
			scaleAxisX,
			scaleAxisY,
			startProjection: (
				(pointerX - oppositeX) * scaleAxisX
				+ (pointerY - oppositeY) * scaleAxisY
			),
			startDiagonal: cornerDistance * 2,
		}
	}

	private updateBounds(): void {
		const halfSize = this.selectionHalfSize()
			+ ROTATION_ZONE_OFFSET
			+ ROTATION_ZONE_SIZE / 2

		this.hitArea = new Rectangle(
			-halfSize,
			-halfSize,
			halfSize * 2,
			halfSize * 2,
		)
	}

	private selectionHalfSize(): number {
		return BOSS_BASE_SIZE * this.currentScale / 2 + SELECTION_PADDING
	}

	private createGraphic(
		context: GraphicsContext,
		pivotX: number,
		pivotY: number,
		scale: number,
		tint: number,
		alpha = 1,
		blurStrength = 0,
		offsetY = 0,
	): Graphics {
		const graphic = new Graphics(context)

		graphic.pivot.set(pivotX, pivotY)
		graphic.position.y = offsetY
		graphic.scale.set(scale)
		graphic.tint = tint
		graphic.alpha = alpha

		if (blurStrength > 0) {
			graphic.filters = [
				new BlurFilter({
					strength: blurStrength,
					quality: 2,
				}),
			]
		}

		return graphic
	}

	private updateGraphicScales(scale: number): void {
		const graphicScale = BOSS_BASE_SIZE / BOSS_HITBOX_VIEWBOX_SIZE * scale

		this.glowGraphic.scale.set(graphicScale)
		this.glowGraphic.position.y = this.hitboxOffsetY * graphicScale
		this.strokeGraphic.scale.set(graphicScale * GLOW_SCALE)
		this.strokeGraphic.position.y = (
			this.hitboxOffsetY * graphicScale * GLOW_SCALE
		)
		this.graphic.scale.set(graphicScale)
		this.graphic.position.y = this.hitboxOffsetY * graphicScale

		const faceBounds = this.faceGraphic.context.bounds
		const faceGraphicScale = (
			BOSS_FACE_BASE_SIZE / Math.max(faceBounds.width, faceBounds.height) * scale
		)

		this.faceGlowGraphic.scale.set(faceGraphicScale)
		this.faceStrokeGraphic.scale.set(
			faceGraphicScale * GLOW_SCALE,
		)
		this.faceGraphic.scale.set(faceGraphicScale)
	}

	private normalizeDegrees(value: number): number {
		const normalized = ((value + 180) % 360 + 360) % 360 - 180

		return normalized === -180 ? 180 : normalized
	}
}
