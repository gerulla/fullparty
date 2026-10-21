export interface PageRippleOrigin {
    x: number
    y: number
    seed: number
}

export interface PageRippleRenderer {
    start(origin?: PageRippleOrigin): PageRippleOrigin
    draw(progress: number, revealing: boolean): void
    clear(): void
    color(): string
}
