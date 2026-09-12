const units = ['B', 'KB', 'MB', 'GB'] as const

export function formatBytes(bytes: number, locale = 'en'): string {
    if (!Number.isFinite(bytes) || bytes < 0) return '-'
    let value = bytes
    let unit = 0
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024
        unit++
    }
    if (unit > 0 && unit < units.length - 1 && Math.round(value * 100) / 100 >= 1024) {
        value /= 1024
        unit++
    }
    return `${new Intl.NumberFormat(locale, { maximumFractionDigits: unit === 0 ? 0 : 2 }).format(value)} ${units[unit]}`
}
