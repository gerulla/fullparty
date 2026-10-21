import type { PageRippleRenderer } from '@/Types/NavigationTransition'

/** Browser-only renderer. Its canvas is decorative and never captures input. */
export function createPageRippleRenderer(canvas: HTMLCanvasElement): PageRippleRenderer | null {
    const context = canvas.getContext('2d')
    if (!context) return null

    let width = 0
    let height = 0
    let originX = 0
    let originY = 0
    let radius = 0
    let seed = 0
    let colors: string[] = []

    function contour(size: number, layer: number) {
        const points = 120
        for (let point = 0; point <= points; point++) {
            const angle = point / points * Math.PI * 2
            const wave = 1
                + Math.sin(angle * 7 + seed + layer * 0.35) * 0.035
                + Math.sin(angle * 13 - seed + layer * 0.25) * 0.018
            const x = originX + Math.cos(angle) * size * wave
            const y = originY + Math.sin(angle) * size * wave
            if (point === 0) context!.moveTo(x, y)
            else context!.lineTo(x, y)
        }
        context!.closePath()
    }

    return {
        start(origin) {
            width = window.innerWidth
            height = window.innerHeight
            // Limit pixel density: this is a moving wash, not fine text or artwork.
            const density = Math.min(window.devicePixelRatio || 1, 1.5)
            canvas.width = Math.ceil(width * density)
            canvas.height = Math.ceil(height * density)
            context.setTransform(density, 0, 0, density, 0, 0)
            const edge = Math.floor(Math.random() * 4)
            const position = 0.1 + Math.random() * 0.8
            originX = origin ? origin.x * width : edge === 1 ? width : edge === 3 ? 0 : width * position
            originY = origin ? origin.y * height : edge === 0 ? 0 : edge === 2 ? height : height * position
            // Even the smallest, most indented wave must cover the farthest corner.
            radius = Math.hypot(Math.max(originX, width - originX), Math.max(originY, height - originY)) * 1.65
            seed = origin?.seed ?? Math.random() * Math.PI * 2
            const style = getComputedStyle(canvas)
            colors = Array.from({ length: 5 }, (_, index) => style.getPropertyValue(`--ripple-${index + 1}`).trim())
            canvas.hidden = false
            return { x: originX / width, y: originY / height, seed }
        },
        draw(progress, revealing) {
            context.clearRect(0, 0, width, height)
            const eased = progress * progress * (3 - 2 * progress)
            for (let layer = 0; layer < 5; layer++) {
                const fraction = revealing ? 0.68 + layer * 0.08 : 1 - layer * 0.08
                const size = radius * eased * fraction
                if (size < 0.01 && !revealing) continue
                const gradient = context.createRadialGradient(originX, originY, Math.max(0, size * 0.82), originX, originY, Math.max(0.01, size * 1.05))
                gradient.addColorStop(0, colors[Math.min(layer + 1, 4)])
                gradient.addColorStop(1, colors[layer])
                context.fillStyle = gradient
                context.beginPath()
                if (revealing) context.rect(0, 0, width, height)
                contour(size, layer)
                context.fill('evenodd')
            }
        },
        clear() {
            canvas.hidden = true
            context.clearRect(0, 0, width, height)
        },
        color: () => colors[4],
    }
}
