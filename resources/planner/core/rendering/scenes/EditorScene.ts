import {
	Container,
	Graphics,
	Rectangle,
	Sprite,
	Texture,
	type GraphicsContext,
	type Texture as PixiTexture,
} from 'pixi.js'
import type {
	RaidPlanArenaMapDisplayMode,
	RaidPlanBossElementComponent,
	RaidPlanMarkerKey,
	RaidPlanMarkerElementComponent,
	RaidPlanMarkerLayoutComponent,
} from '../../../types/RaidPlan'
import { resolveMarkerLayoutPoints } from '../../../utils/markerLayouts'
import { BossElement } from '../../elements/BossElement'
import { MarkerElement } from '../../elements/MarkerElement'
import type { PlannerScene } from '../PlannerScene'
import { PLANNER_STAGE_HEIGHT, PLANNER_STAGE_WIDTH } from '../PlannerStage'

interface ArenaMapRenderData {
	texture: PixiTexture
	displayMode: RaidPlanArenaMapDisplayMode
	offsetX: number
	offsetY: number
	rotation: number
	cropLeft: number
	cropRight: number
	cropTop: number
	cropBottom: number
}

interface BossElementRenderData {
	component: RaidPlanBossElementComponent
	context: GraphicsContext
	faceContext: GraphicsContext
	hitboxOffsetY: number
}

interface MarkerElementRenderData {
	component: RaidPlanMarkerElementComponent
	texture: PixiTexture
}

interface MarkerLayoutRenderData {
	component: RaidPlanMarkerLayoutComponent
	textures: Record<RaidPlanMarkerKey, PixiTexture>
}

interface EditorSceneOptions {
	arenaMap?: ArenaMapRenderData | null
	bosses?: BossElementRenderData[]
	markers?: MarkerElementRenderData[]
	markerLayouts?: MarkerLayoutRenderData[]
	selectedElementId?: string | null
	editable?: boolean
	onSelectElement?: (elementId: string) => void
	onElementContextMenu?: (elementId: string, clientX: number, clientY: number) => void
	onChangeElement?: (
		elementId: string,
		changes:
			| Partial<RaidPlanBossElementComponent>
			| Partial<RaidPlanMarkerElementComponent>,
	) => void
	onTransformStart?: () => void
	onTransformEnd?: () => void
	requestRender?: () => void
}

export class EditorScene implements PlannerScene {
	private derivedTexture: Texture | null = null

	public constructor(
		private readonly options: EditorSceneOptions = {},
	) {}

	public build(): Container {
		const scene = new Container()

		scene.addChild(
			new Graphics()
				.rect(0, 0, PLANNER_STAGE_WIDTH, PLANNER_STAGE_HEIGHT)
				.fill({ color: 0x100d14 }),
		)

		if (this.options.arenaMap) {
			scene.addChild(this.createArenaMap(this.options.arenaMap))
		}

		for (const markerLayout of this.options.markerLayouts ?? []) {
			scene.addChild(this.createMarkerLayout(markerLayout))
		}

		for (const boss of this.options.bosses ?? []) {
			scene.addChild(new BossElement(
				boss.context,
				boss.faceContext,
				boss.hitboxOffsetY,
				{
					data: boss.component,
					selected: boss.component.id === this.options.selectedElementId,
					editable: this.options.editable ?? false,
					onSelect: elementId => this.options.onSelectElement?.(elementId),
					onContextMenu: (elementId, clientX, clientY) => (
						this.options.onElementContextMenu?.(elementId, clientX, clientY)
					),
					onChange: (elementId, changes) => (
						this.options.onChangeElement?.(elementId, changes)
					),
					onTransformStart: this.options.onTransformStart,
					onTransformEnd: this.options.onTransformEnd,
					requestRender: this.options.requestRender,
				},
			))
		}

		for (const marker of this.options.markers ?? []) {
			scene.addChild(new MarkerElement(
				marker.texture,
				{
					data: marker.component,
					selected: marker.component.id === this.options.selectedElementId,
					editable: this.options.editable ?? false,
					onSelect: elementId => this.options.onSelectElement?.(elementId),
					onContextMenu: (elementId, clientX, clientY) => (
						this.options.onElementContextMenu?.(elementId, clientX, clientY)
					),
					onChange: (elementId, changes) => (
						this.options.onChangeElement?.(elementId, changes)
					),
					onTransformStart: this.options.onTransformStart,
					onTransformEnd: this.options.onTransformEnd,
					requestRender: this.options.requestRender,
				},
			))
		}

		return scene
	}

	public destroy(): void {
		this.derivedTexture?.destroy(false)
		this.derivedTexture = null
	}

	private createArenaMap(data: ArenaMapRenderData): Sprite {
		const texture = data.displayMode === 'crop'
			? this.createCroppedTexture(data)
			: data.texture
		const map = new Sprite(texture)

		map.anchor.set(0.5)
		map.position.set(
			PLANNER_STAGE_WIDTH / 2 + data.offsetX,
			PLANNER_STAGE_HEIGHT / 2 + data.offsetY,
		)
		map.rotation = data.rotation * (Math.PI / 180)

		if (data.displayMode === 'fill') {
			map.width = PLANNER_STAGE_WIDTH
			map.height = PLANNER_STAGE_HEIGHT
		} else {
			const scale = data.displayMode === 'crop'
				? Math.max(
					PLANNER_STAGE_WIDTH / texture.width,
					PLANNER_STAGE_HEIGHT / texture.height,
				)
				: Math.min(
					PLANNER_STAGE_WIDTH / texture.width,
					PLANNER_STAGE_HEIGHT / texture.height,
				)

			map.scale.set(scale)
		}

		return map
	}

	private createMarkerLayout(data: MarkerLayoutRenderData): Container {
		const layout = new Container()
		const points = resolveMarkerLayoutPoints(
			data.component.layout,
			data.component.distance,
			data.component.waymark_preset,
		)

		layout.position.set(
			PLANNER_STAGE_WIDTH / 2 + data.component.offset_x,
			PLANNER_STAGE_HEIGHT / 2 + data.component.offset_y,
		)
		layout.rotation = data.component.rotation * (Math.PI / 180)

		for (const [markerKey, point] of Object.entries(points)) {
			const marker = new Sprite(
				data.textures[markerKey as RaidPlanMarkerKey],
			)

			marker.anchor.set(0.5)
			marker.position.set(point.x, point.y)
			marker.width = 52
			marker.height = 52
			layout.addChild(marker)
		}

		return layout
	}

	private createCroppedTexture(data: ArenaMapRenderData): Texture {
		const sourceFrame = data.texture.frame
		const left = this.cropPixels(data.cropLeft, sourceFrame.width)
		const right = this.cropPixels(data.cropRight, sourceFrame.width)
		const top = this.cropPixels(data.cropTop, sourceFrame.height)
		const bottom = this.cropPixels(data.cropBottom, sourceFrame.height)
		const width = Math.max(1, sourceFrame.width - left - right)
		const height = Math.max(1, sourceFrame.height - top - bottom)

		this.derivedTexture = new Texture({
			source: data.texture.source,
			frame: new Rectangle(
				sourceFrame.x + left,
				sourceFrame.y + top,
				width,
				height,
			),
		})

		return this.derivedTexture
	}

	private cropPixels(value: number, dimension: number): number {
		return dimension * Math.min(99, Math.max(0, value)) / 100
	}
}
