import {
	Graphics,
	Rectangle,
	Sprite,
	type Texture,
} from 'pixi.js'
import type { RaidPlanMarkerElementComponent } from '../../types/RaidPlan'
import {
	RaidPlanElement,
	type RaidPlanElementOptions,
} from './RaidPlanElement'

const MARKER_SIZE = 52
const SELECTION_PADDING = 5

export class MarkerElement extends RaidPlanElement<RaidPlanMarkerElementComponent> {
	public constructor(
		texture: Texture,
		options: RaidPlanElementOptions<RaidPlanMarkerElementComponent>,
	) {
		super(options)

		const size = MARKER_SIZE * options.data.scale
		const marker = new Sprite(texture)

		marker.anchor.set(0.5)
		marker.width = size
		marker.height = size
		this.addChild(marker)

		if (options.selected) {
			const halfSize = size / 2 + SELECTION_PADDING

			this.addChild(
				new Graphics()
					.rect(-halfSize, -halfSize, halfSize * 2, halfSize * 2)
					.stroke({ color: 0xffffff, width: 1 }),
			)
		}

		const hitboxHalfSize = size / 2 + SELECTION_PADDING

		this.hitArea = new Rectangle(
			-hitboxHalfSize,
			-hitboxHalfSize,
			hitboxHalfSize * 2,
			hitboxHalfSize * 2,
		)
	}
}
