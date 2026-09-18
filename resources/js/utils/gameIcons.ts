import type { GameIcon, GameIconAttributes } from '../Types/GameIcons'

export function normalizeIconQuery(value: string): string {
    return value.normalize('NFKD').replace(/\p{M}/gu, '').toLocaleLowerCase().replace(/[^\p{L}\p{N}]/gu, '')
}

export function findGameIcon(icons: GameIcon[], shortcode: string): GameIcon | undefined {
    const query = normalizeIconQuery(shortcode)
    if (!query) return undefined
    const canonical = icons.filter(icon => normalizeIconQuery(icon.shortcode) === query)
    if (canonical.length === 1) return canonical[0]
    const matches = icons.filter(icon => icon.aliases.some(alias => normalizeIconQuery(alias) === query))
    return matches.length === 1 ? matches[0] : undefined
}

export function searchGameIcons(icons: GameIcon[], query: string, limit = 40): GameIcon[] {
    const normalized = normalizeIconQuery(query)
    if (!normalized) return icons.slice(0, limit)
    return icons.map(icon => {
        const names = [icon.shortcode, ...icon.aliases, ...Object.values(icon.names)].map(normalizeIconQuery)
        const score = names.some(name => name === normalized) ? 0
            : names.some(name => name.startsWith(normalized)) ? 1
                : names.some(name => name.includes(normalized)) ? 2
                    : normalizeIconQuery(`${icon.category} ${icon.job}`).includes(normalized) ? 3 : 4
        return { icon, score }
    }).filter(match => match.score < 4).sort((a, b) => a.score - b.score).slice(0, limit).map(match => match.icon)
}

export function gameIconAttributes(icon: GameIcon): GameIconAttributes {
    return { key: icon.key, shortcode: icon.shortcode, src: icon.src }
}

export function safeGameIconSource(src: unknown): string | null {
    if (typeof src !== 'string' || src.length > 2048 || /[\\\s\u0000-\u001f]/.test(src)) return null
    try {
        const decoded = decodeURIComponent(src)
        if (decoded.includes('..') || decoded.includes('\\')) return null
        return /^\/(?:reference-icons|seed-data|newjobs|role-icons|CalculatorData|BozjaInfo)\/.+\.(?:png|webp)$/.test(src) ? src : null
    } catch { return null }
}
