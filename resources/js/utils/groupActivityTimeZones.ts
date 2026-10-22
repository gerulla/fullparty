import locations from '@/assets/maps/timezone-locations.json'
import type { GroupTimeZoneOption } from '@/Types/Groups'

function canonicalTimeZone(value: string): string | null {
    try {
        return new Intl.DateTimeFormat('en', { timeZone: value }).resolvedOptions().timeZone
    } catch {
        return null
    }
}

// Keep regional IANA names: equal offsets today do not imply equal daylight-saving rules.
export function groupActivityTimeZoneOptions(at: Date, detectedTimeZone: string): GroupTimeZoneOption[] {
    const supportedValuesOf = (Intl as typeof Intl & { supportedValuesOf?: (key: 'timeZone') => string[] }).supportedValuesOf
    const values = new Set([...(supportedValuesOf?.('timeZone') ?? Object.keys(locations.zones)), 'UTC', detectedTimeZone])
    const coordinates = new Map(Object.entries(locations.zones).map(([zone, point]) => [canonicalTimeZone(zone), { zone, point }]))

    return [...values].flatMap(value => {
        const canonical = canonicalTimeZone(value)
        if (!canonical) return []
        const offset = new Intl.DateTimeFormat('en', { timeZone: value, timeZoneName: 'shortOffset' })
            .formatToParts(at).find(part => part.type === 'timeZoneName')?.value ?? 'GMT'
        const offsetLabel = offset.replace('GMT', 'UTC')
        const location = coordinates.get(canonical)
        const point = location?.point
        // ICU can still return older aliases such as Calcutta or Katmandu.
        const displayName = location?.zone ?? value
        return [{
            value,
            label: `${displayName.replaceAll('_', ' ')} · ${offsetLabel}`,
            city: displayName.split('/').at(-1)!.replaceAll('_', ' '),
            offsetLabel,
            // Same equirectangular projection as the existing world land outlines.
            ...(point ? { x: (point[1] + 180) / 360 * 1000, y: (85 - point[0]) / 360 * 1000 } : {}),
        }]
    }).sort((left, right) => left.value.localeCompare(right.value))
}
