import type { Container } from 'pixi.js'

export interface PlannerScene {
	build(): Container
	destroy?(): void
}
