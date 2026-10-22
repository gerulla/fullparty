import type { DatacenterRegion } from '@/Types/Groups'

/** A starting suggestion only: a time zone cannot tell us where someone plays. */
export function datacenterRegionForTimezone(timezone: string): DatacenterRegion | null {
    let canonical: string
    try {
        canonical = new Intl.DateTimeFormat('en', { timeZone: timezone }).resolvedOptions().timeZone
    } catch {
        return null
    }

    if (/^(America|US|Canada|Brazil|Chile|Mexico)\//.test(canonical)
        || ['Pacific/Honolulu', 'Pacific/Johnston', 'Pacific/Easter', 'Pacific/Galapagos', 'Atlantic/Bermuda'].includes(canonical)) {
        return 'NA'
    }
    if (/^(Europe|Africa|Atlantic)\//.test(canonical)
        || /^Asia\/(Jerusalem|Tel_Aviv|Dubai|Muscat|Aden|Riyadh|Qatar|Bahrain|Kuwait|Beirut|Damascus|Amman|Baghdad|Tehran|Gaza|Hebron|Istanbul|Nicosia|Famagusta)$/.test(canonical)) {
        return 'EU'
    }
    if (canonical.startsWith('Asia/') || /^Pacific\/(Guam|Saipan|Palau)$/.test(canonical)) {
        return 'JP'
    }
    if (/^(Australia|Pacific)\//.test(canonical) || canonical === 'Antarctica/Macquarie') {
        return 'OCE'
    }

    return null
}
