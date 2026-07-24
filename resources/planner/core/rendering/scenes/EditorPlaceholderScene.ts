import { Container, Graphics, Text } from 'pixi.js'
import type { PlannerScene } from '../PlannerScene'
import { PLANNER_STAGE_HEIGHT, PLANNER_STAGE_WIDTH } from '../PlannerStage'

const ARENA_SIZE = 620
const ARENA_X = (PLANNER_STAGE_WIDTH - ARENA_SIZE) / 2
const ARENA_Y = (PLANNER_STAGE_HEIGHT - ARENA_SIZE) / 2

export class EditorPlaceholderScene implements PlannerScene {
	public build(): Container {
		const scene = new Container()

		scene.addChild(this.createBackground())
		scene.addChild(this.createArena())
		scene.addChild(this.createActors())

		return scene
	}

	private createBackground(): Graphics {
		return new Graphics()
			.rect(0, 0, PLANNER_STAGE_WIDTH, PLANNER_STAGE_HEIGHT)
			.fill({ color: 0x100d14 })
	}

	private createArena(): Container {
		const arena = new Container()
		const floor = new Graphics()
			.rect(ARENA_X, ARENA_Y, ARENA_SIZE, ARENA_SIZE)
			.fill({ color: 0x17121d })
			.stroke({ color: 0x6f4a85, width: 3, alpha: 0.9 })
		const grid = new Graphics()
		const gridStep = ARENA_SIZE / 8

		for (let index = 1; index < 8; index += 1) {
			const offset = gridStep * index

			grid.moveTo(ARENA_X + offset, ARENA_Y)
				.lineTo(ARENA_X + offset, ARENA_Y + ARENA_SIZE)
			grid.moveTo(ARENA_X, ARENA_Y + offset)
				.lineTo(ARENA_X + ARENA_SIZE, ARENA_Y + offset)
		}

		grid.stroke({ color: 0x514058, width: 1, alpha: 0.35 })
		arena.addChild(floor, grid)

		for (const [label, x, y] of [
			['N', PLANNER_STAGE_WIDTH / 2, ARENA_Y + 24],
			['E', ARENA_X + ARENA_SIZE - 24, PLANNER_STAGE_HEIGHT / 2],
			['S', PLANNER_STAGE_WIDTH / 2, ARENA_Y + ARENA_SIZE - 24],
			['W', ARENA_X + 24, PLANNER_STAGE_HEIGHT / 2],
		] as const) {
			const marker = new Text({
				text: label,
				style: {
					fill: 0x8f8298,
					fontFamily: 'IBM Plex Mono',
					fontSize: 18,
					fontWeight: '600',
				},
			})

			marker.anchor.set(0.5)
			marker.position.set(x, y)
			arena.addChild(marker)
		}

		return arena
	}

	private createActors(): Container {
		const actors = new Container()
		const centerX = PLANNER_STAGE_WIDTH / 2
		const centerY = PLANNER_STAGE_HEIGHT / 2

		actors.addChild(this.createActor(centerX, centerY - 110, 0xb276d2, 'B'))

		const playerPositions = [
			[-95, 70, 0x537dbd, 'MT'],
			[95, 70, 0x537dbd, 'OT'],
			[-95, 145, 0x5b9b73, 'H1'],
			[95, 145, 0x5b9b73, 'H2'],
			[-155, 215, 0xb5545e, 'M1'],
			[-50, 215, 0xb5545e, 'M2'],
			[50, 215, 0xb5545e, 'R1'],
			[155, 215, 0xb5545e, 'R2'],
		] as const

		for (const [x, y, color, label] of playerPositions) {
			actors.addChild(this.createActor(centerX + x, centerY + y, color, label))
		}

		return actors
	}

	private createActor(x: number, y: number, color: number, label: string): Container {
		const actor = new Container()
		const token = new Graphics()
			.circle(0, 0, 24)
			.fill({ color, alpha: 0.95 })
			.stroke({ color: 0xf3eefa, width: 2, alpha: 0.8 })
		const text = new Text({
			text: label,
			style: {
				fill: 0xffffff,
				fontFamily: 'IBM Plex Sans',
				fontSize: label.length > 1 ? 13 : 18,
				fontWeight: '700',
			},
		})

		text.anchor.set(0.5)
		actor.position.set(x, y)
		actor.addChild(token, text)

		return actor
	}
}
