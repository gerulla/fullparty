import { Application } from 'pixi.js'
import type { PlannerScene } from './PlannerScene'
import { PLANNER_STAGE_HEIGHT, PLANNER_STAGE_WIDTH } from './PlannerStage'

export class PlannerRenderer {
	private application: Application | null = null
	private disposed = false

	public constructor(
		private readonly scene: PlannerScene,
		private readonly accessibleLabel: string,
	) {}

	public async mount(host: HTMLElement): Promise<void> {
		const application = new Application()

		await application.init({
			width: PLANNER_STAGE_WIDTH,
			height: PLANNER_STAGE_HEIGHT,
			backgroundColor: 0x100d14,
			antialias: true,
			autoStart: false,
			resolution: 1,
		})

		if (this.disposed) {
			application.destroy(true)

			return
		}

		this.application = application
		application.stage.addChild(this.scene.build())
		application.renderer.render(application.stage)
		application.canvas.className = 'block size-full'
		application.canvas.setAttribute('aria-label', this.accessibleLabel)
		application.canvas.setAttribute('role', 'img')
		host.replaceChildren(application.canvas)
	}

	public destroy(): void {
		this.disposed = true
		this.application?.destroy(true, { children: true })
		this.application = null
	}
}
