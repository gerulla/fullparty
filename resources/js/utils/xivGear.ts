import { Node } from '@tiptap/core'
import type { GearNames, GearsetSnapshot } from '@/Types/XivGear'

export function isXivGearUrl(value: unknown): value is string {
    if (typeof value !== 'string' || value.length > 2048 || /[\s\\]/.test(value)) return false
    if (!/^https:\/\/(?:xivgear\.app|www\.xivgear\.app|share\.xivgear\.app)(?:[/?#]|$)/i.test(value)) return false
    try {
        const url = new URL(value)
        return url.protocol === 'https:' && !url.username && !url.password && !url.port
    } catch { return false }
}

const record = (value: unknown): value is Record<string, unknown> => value !== null && typeof value === 'object' && !Array.isArray(value)
const numericRecord = (value: unknown) => record(value) && Object.values(value).every(number => typeof number === 'number' && Number.isFinite(number))
const locales: (keyof GearNames)[] = ['en', 'de', 'fr', 'ja']

function validItem(item: unknown) {
    if (!record(item) || !record(item.names)) return false
    const names = item.names
    return Number.isInteger(item.id) && Number(item.id) > 0 && Number.isInteger(item.itemLevel)
        && locales.every(locale => typeof names[locale] === 'string')
        && (item.icon === null || (Number.isInteger(item.icon) && Number(item.icon) > 0 && Number(item.icon) <= 999999))
}

function validSet(set: unknown): boolean {
    if (!record(set) || set.version !== 1 || !isXivGearUrl(set.sourceUrl)) return false
    if (typeof set.name !== 'string' || typeof set.description !== 'string' || typeof set.job !== 'string') return false
    if (typeof set.importedAt !== 'string' || !Number.isFinite(new Date(set.importedAt).getTime())) return false
    if (!Number.isInteger(set.level) || !Number.isInteger(set.itemLevel) || !Number.isInteger(set.partyBonus)) return false
    if (set.gcd !== null && (typeof set.gcd !== 'number' || !Number.isFinite(set.gcd))) return false
    if (!numericRecord(set.stats) || (set.food !== null && !validItem(set.food))) return false
    if (!Array.isArray(set.items) || !set.items.length || set.items.length > 12) return false
    return set.items.every(item => record(item) && validItem(item) && typeof item.slot === 'string'
        && Array.isArray(item.materia) && item.materia.length <= 5 && item.materia.every(validItem) && numericRecord(item.relicStats))
}

export function parseGearsetSnapshots(value: string | null): GearsetSnapshot[] | null {
    if (!value || value.length > 2000000) return null
    try {
        const sets = JSON.parse(value)
        return Array.isArray(sets) && sets.length > 0 && sets.length <= 20 && sets.every(validSet) ? sets : null
    } catch { return null }
}

export const xivGearExtension = () => Node.create({
    name: 'xivGear', group: 'block', atom: true, draggable: true,
    addAttributes: () => ({
        display: { default: 'expanded', parseHTML: element => element.getAttribute('data-display') === 'compact' ? 'compact' : 'expanded' },
        snapshots: { default: [], parseHTML: element => parseGearsetSnapshots(element.getAttribute('data-xivgear')) ?? [] },
    }),
    parseHTML: () => [{ tag: 'div[data-xivgear]', getAttrs: element => parseGearsetSnapshots(element.getAttribute('data-xivgear')) ? {} : false }],
    renderHTML: ({ node }) => ['div', { 'data-xivgear': JSON.stringify(node.attrs.snapshots), 'data-display': node.attrs.display }],
})
