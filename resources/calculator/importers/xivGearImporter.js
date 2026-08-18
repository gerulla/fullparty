import { getLevelStats, normalizeCombatStats, STAT_KEYS } from '../math/combatMath.js'

export const XIV_GEAR_API_BASE_URL = 'https://api.xivgear.app'
export const XIV_GEAR_DATA_API_BASE_URL = 'https://data.xivgear.app'

export const XIV_GEAR_STAT_MAP = Object.freeze({
    criticalHit: 'crit',
    det: 'determination',
    directHit: 'dhit',
    directHitRate: 'dhit',
    magicDefense: 'defenseMag',
    physicalWeaponDamage: 'wdPhys',
    skillSpeed: 'skillspeed',
    sks: 'skillspeed',
    spellSpeed: 'spellspeed',
    sps: 'spellspeed',
    ten: 'tenacity',
    weaponDamage: 'wdPhys',
})

export const XIV_GEAR_EQUIPMENT_SLOTS = Object.freeze([
    'Weapon',
    'OffHand',
    'Head',
    'Body',
    'Hand',
    'Legs',
    'Feet',
    'Ears',
    'Neck',
    'Wrist',
    'RingLeft',
    'RingRight',
])

export const XIV_GEAR_EQUIPMENT_SLOT_LABELS = Object.freeze({
    Weapon: 'Weapon',
    OffHand: 'Off-hand',
    Head: 'Head',
    Body: 'Body',
    Hand: 'Hands',
    Legs: 'Legs',
    Feet: 'Feet',
    Ears: 'Earrings',
    Neck: 'Necklace',
    Wrist: 'Bracelet',
    RingLeft: 'Left Ring',
    RingRight: 'Right Ring',
    Food: 'Food',
})

const DEFAULT_SET_INDEX = 0
const DEFAULT_PARTY_BONUS = 0
const referenceDataCache = new Map()

export function buildXivGearFulldataUrl(xivGearUrl, options = {}) {
    const endpoint = new URL('/fulldata', options.apiBaseUrl ?? XIV_GEAR_API_BASE_URL)
    const sourceUrl = normalizeSourceUrl(xivGearUrl)

    endpoint.searchParams.set('url', sourceUrl)

    if (Number.isInteger(options.onlySetIndex)) {
        endpoint.searchParams.set('onlySetIndex', String(options.onlySetIndex))
    }

    const partyBonus = Number.isInteger(options.partyBonus) ? options.partyBonus : DEFAULT_PARTY_BONUS
    endpoint.searchParams.set('partyBonus', String(partyBonus))

    return endpoint.toString()
}

export function buildXivGearItemsUrl(job, options = {}) {
    const normalizedJob = normalizeJob(job)

    if (!normalizedJob) {
        throw new Error('XivGear job is required to retrieve item reference data')
    }

    const endpoint = new URL('/Items', options.dataApiBaseUrl ?? XIV_GEAR_DATA_API_BASE_URL)
    endpoint.searchParams.set('job', normalizedJob)

    return endpoint.toString()
}

export function buildXivGearMateriaUrl(options = {}) {
    return new URL('/Materia', options.dataApiBaseUrl ?? XIV_GEAR_DATA_API_BASE_URL).toString()
}

export function buildXivGearFoodUrl(options = {}) {
    return new URL('/Food', options.dataApiBaseUrl ?? XIV_GEAR_DATA_API_BASE_URL).toString()
}

export function parseXivGearJson(input) {
    if (typeof input !== 'string') {
        return input
    }

    const trimmed = input.trim()

    if (!trimmed) {
        throw new Error('XivGear import payload is empty')
    }

    try {
        return JSON.parse(trimmed)
    } catch (error) {
        throw new Error('XivGear import payload must be parsed JSON or a plain object', { cause: error })
    }
}

export async function fetchXivGearPayload(xivGearUrl, options = {}) {
    const fetchImpl = options.fetch ?? globalThis.fetch

    if (typeof fetchImpl !== 'function') {
        throw new Error('A fetch implementation is required to retrieve XivGear data')
    }

    const response = await fetchImpl(buildXivGearFulldataUrl(xivGearUrl, options))

    if (!response.ok) {
        const body = typeof response.text === 'function' ? await response.text() : ''

        throw new Error(`XivGear fulldata request failed with status ${response.status}${body ? `: ${body}` : ''}`)
    }

    return importXivGearPayload(await response.json(), options)
}

export async function fetchXivGearSet(xivGearUrl, options = {}) {
    const imported = await fetchXivGearPayload(xivGearUrl, options)
    const setIndex = options.setIndex ?? DEFAULT_SET_INDEX
    const set = imported.sets[setIndex]

    if (!set) {
        throw new Error(`XivGear import set index ${setIndex} was not found`)
    }

    return set
}

export async function fetchXivGearReferenceData(job, options = {}) {
    const normalizedJob = normalizeJob(job)
    const fetchImpl = options.fetch ?? globalThis.fetch

    if (!normalizedJob) {
        throw new Error('XivGear job is required to retrieve item reference data')
    }

    if (typeof fetchImpl !== 'function') {
        throw new Error('A fetch implementation is required to retrieve XivGear reference data')
    }

    const dataApiBaseUrl = options.dataApiBaseUrl ?? XIV_GEAR_DATA_API_BASE_URL
    const cacheKey = `${dataApiBaseUrl}|${normalizedJob}`

    if (!options.forceRefresh && referenceDataCache.has(cacheKey)) {
        return referenceDataCache.get(cacheKey)
    }

    const request = Promise.all([
        fetchXivGearDataEndpoint(buildXivGearItemsUrl(normalizedJob, { dataApiBaseUrl }), fetchImpl, 'items'),
        fetchXivGearDataEndpoint(buildXivGearMateriaUrl({ dataApiBaseUrl }), fetchImpl, 'materia'),
        fetchXivGearDataEndpoint(buildXivGearFoodUrl({ dataApiBaseUrl }), fetchImpl, 'food'),
    ]).then(([itemsPayload, materiaPayload, foodPayload]) => normalizeReferenceData({
        itemsPayload,
        materiaPayload,
        foodPayload,
    }))

    referenceDataCache.set(cacheKey, request)

    try {
        return await request
    } catch (error) {
        referenceDataCache.delete(cacheKey)
        throw error
    }
}

export function importXivGearPayload(input, options = {}) {
    const payload = parseXivGearJson(input)
    const sets = extractSetEntries(payload, options)

    return Object.freeze({
        source: 'xivgear',
        sheet: normalizeSheetMetadata(payload),
        sets: Object.freeze(sets.map((entry, index) => normalizeSetEntry(entry, index))),
    })
}

export function importXivGearSet(input, options = {}) {
    const imported = importXivGearPayload(input, options)
    const setIndex = options.setIndex ?? DEFAULT_SET_INDEX
    const set = imported.sets[setIndex]

    if (!set) {
        throw new Error(`XivGear import set index ${setIndex} was not found`)
    }

    return set
}

export function toCombatMathConfig(importedSet) {
    return Object.freeze({
        level: importedSet.level,
        job: importedSet.job,
        stats: importedSet.stats,
        jobStatMultipliers: importedSet.jobStatMultipliers,
    })
}

export function extractXivGearEquipment(importedSet) {
    const items = importedSet?.rawSet?.items ?? importedSet?.items ?? {}
    const equipment = []

    for (const slot of XIV_GEAR_EQUIPMENT_SLOTS) {
        const item = items?.[slot]

        if (!item) {
            continue
        }

        equipment.push(normalizeEquipmentItem(slot, item))
    }

    if (importedSet?.foodId) {
        equipment.push(Object.freeze({
            slot: 'Food',
            label: XIV_GEAR_EQUIPMENT_SLOT_LABELS.Food,
            id: importedSet.foodId,
            name: null,
            iconUrl: null,
            forceNq: false,
            materia: Object.freeze([]),
            relicStats: null,
        }))
    }

    return Object.freeze(equipment)
}

export function enrichXivGearEquipment(equipment, referenceData, options = {}) {
    const locale = normalizeLocale(options.locale)

    if (!Array.isArray(equipment) || !referenceData) {
        return Object.freeze([])
    }

    return Object.freeze(equipment.map(item => {
        const itemReference = item.slot === 'Food'
            ? referenceById(referenceData.foodById, item.id) ?? referenceById(referenceData.itemsById, item.id)
            : referenceById(referenceData.itemsById, item.id)

        return Object.freeze({
            ...item,
            name: localizedReferenceName(itemReference, locale) ?? item.name ?? null,
            iconUrl: itemReference?.iconUrl ?? item.iconUrl ?? null,
            materia: Object.freeze((item.materia ?? []).map(materia => {
                const materiaReference = referenceById(referenceData.materiaById, materia.id)

                return Object.freeze({
                    ...materia,
                    name: localizedReferenceName(materiaReference, locale) ?? materia.name ?? null,
                    iconUrl: materiaReference?.iconUrl ?? materia.iconUrl ?? null,
                })
            })),
        })
    }))
}

export function isXivGearFulldataPayload(input) {
    const payload = parseXivGearJson(input)

    return Boolean(findComputedStats(payload) || payload?.sets?.some?.(set => findComputedStats(set)))
}

function normalizeEquipmentItem(slot, item) {
    return Object.freeze({
        slot,
        label: XIV_GEAR_EQUIPMENT_SLOT_LABELS[slot] ?? slot,
        id: integerOrNull(item.id ?? item.itemId ?? item.item?.id),
        name: stringOrNull(item.name ?? item.item?.name),
        iconUrl: stringOrNull(item.iconUrl ?? item.icon?.url ?? item.item?.icon?.url),
        forceNq: Boolean(item.forceNq),
        materia: normalizeMateria(item.materia),
        relicStats: isPlainObject(item.relicStats) ? Object.freeze({ ...item.relicStats }) : null,
    })
}

function normalizeMateria(materia) {
    if (!Array.isArray(materia)) {
        return Object.freeze([])
    }

    return Object.freeze(materia
        .map((entry, index) => {
            const value = typeof entry === 'number' ? { id: entry } : entry
            const id = integerOrNull(value?.id ?? value?.itemId ?? value?.item?.id)

            if (!id || id < 0) {
                return null
            }

            return Object.freeze({
                index,
                id,
                name: stringOrNull(value?.name ?? value?.item?.name),
                iconUrl: stringOrNull(value?.iconUrl ?? value?.icon?.url ?? value?.item?.icon?.url),
                locked: Boolean(value?.locked),
            })
        })
        .filter(Boolean))
}

async function fetchXivGearDataEndpoint(url, fetchImpl, label) {
    const response = await fetchImpl(url)

    if (!response.ok) {
        const body = typeof response.text === 'function' ? await response.text() : ''

        throw new Error(`XivGear ${label} request failed with status ${response.status}${body ? `: ${body}` : ''}`)
    }

    return response.json()
}

function normalizeReferenceData({ itemsPayload, materiaPayload, foodPayload }) {
    return Object.freeze({
        itemsById: buildReferenceMap(referenceItems(itemsPayload)),
        materiaById: buildReferenceMap(referenceMateriaItems(materiaPayload)),
        foodById: buildReferenceMap(referenceItems(foodPayload), { extraIdKeys: ['foodItemId'] }),
    })
}

function referenceItems(payload) {
    if (Array.isArray(payload)) {
        return payload
    }

    if (Array.isArray(payload?.items)) {
        return payload.items
    }

    return []
}

function referenceMateriaItems(payload) {
    return referenceItems(payload).flatMap(entry => {
        if (Array.isArray(entry?.item)) {
            return entry.item
        }

        if (Array.isArray(entry?.items)) {
            return entry.items
        }

        return [entry]
    })
}

function buildReferenceMap(entries, options = {}) {
    const map = new Map()

    for (const entry of entries) {
        addReferenceEntry(map, entry, options.extraIdKeys ?? [])
    }

    return map
}

function addReferenceEntry(map, entry, extraIdKeys) {
    const reference = normalizeReferenceEntry(entry)

    if (!reference) {
        return
    }

    const ids = [
        entry?.primaryKey,
        entry?.rowId,
        entry?.id,
        entry?.itemId,
        entry?.item?.id,
        ...extraIdKeys.map(key => entry?.[key]),
    ]
        .map(integerOrNull)
        .filter(id => id && id > 0)

    if (reference.id && reference.id > 0) {
        ids.push(reference.id)
    }

    for (const id of new Set(ids)) {
        map.set(id, reference)
    }
}

function normalizeReferenceEntry(entry) {
    if (!isPlainObject(entry)) {
        return null
    }

    const name = stringOrNull(entry.name ?? entry.item?.name)

    return Object.freeze({
        id: integerOrNull(entry.primaryKey ?? entry.rowId ?? entry.id ?? entry.itemId ?? entry.item?.id),
        name,
        nameTranslations: isPlainObject(entry.nameTranslations)
            ? Object.freeze({ ...entry.nameTranslations })
            : null,
        iconUrl: stringOrNull(entry.icon?.url ?? entry.iconUrl ?? entry.item?.icon?.url),
    })
}

function referenceById(map, id) {
    if (!(map instanceof Map) || !id) {
        return null
    }

    return map.get(id) ?? null
}

function localizedReferenceName(reference, locale) {
    if (!reference) {
        return null
    }

    const translations = reference.nameTranslations

    if (translations) {
        const localeKey = locale.split('-')[0]

        return stringOrNull(translations[locale])
            ?? stringOrNull(translations[localeKey])
            ?? stringOrNull(translations.en)
            ?? reference.name
            ?? null
    }

    return reference.name ?? null
}

function normalizeLocale(locale) {
    return typeof locale === 'string' && locale.trim() ? locale.trim() : 'en'
}

function normalizeSourceUrl(xivGearUrl) {
    if (xivGearUrl instanceof URL) {
        return xivGearUrl.toString()
    }

    if (typeof xivGearUrl !== 'string' || !xivGearUrl.trim()) {
        throw new Error('XivGear URL is required')
    }

    return xivGearUrl.trim()
}

function normalizeSheetMetadata(payload) {
    return Object.freeze({
        name: stringOrNull(payload?.name),
        description: stringOrNull(payload?.description),
        job: normalizeJob(payload?.job),
        level: integerOrNull(payload?.level),
        partyBonus: integerOrNull(payload?.partyBonus),
        race: stringOrNull(payload?.race),
        timestamp: integerOrNull(payload?.timestamp),
        ilvlSync: integerOrNull(payload?.ilvlSync),
        isMultiJob: Boolean(payload?.isMultiJob),
    })
}

function extractSetEntries(payload, options = {}) {
    if (!payload || typeof payload !== 'object') {
        throw new Error('XivGear import payload must be an object')
    }

    if (Array.isArray(payload.sets)) {
        const entries = payload.sets
            .map((set, index) => ({ sheet: payload, set, sourceIndex: index }))
            .filter(entry => options.includeSeparators || !entry.set?.isSeparator)

        if (entries.length === 0) {
            throw new Error('XivGear import payload does not contain any playable sets')
        }

        return entries
    }

    if (payload.items || findComputedStats(payload)) {
        return [{ sheet: payload, set: payload, sourceIndex: 0 }]
    }

    throw new Error('XivGear import payload does not look like a sheet or set export')
}

function normalizeSetEntry(entry, index) {
    const computedStats = findComputedStats(entry.set)

    if (!computedStats) {
        throw new Error(
            `XivGear set "${entry.set?.name ?? index}" is missing computedStats. Use the XivGear /fulldata endpoint before importing.`,
        )
    }

    const job = normalizeJob(entry.set?.jobOverride ?? computedStats.job ?? entry.set?.job ?? entry.sheet?.job)
    const level = integerOrNull(computedStats.level ?? entry.set?.level ?? entry.sheet?.level)

    if (!job) {
        throw new Error(`XivGear set "${entry.set?.name ?? index}" is missing a job`)
    }

    if (!level) {
        throw new Error(`XivGear set "${entry.set?.name ?? index}" is missing a level`)
    }

    return Object.freeze({
        source: 'xivgear',
        sourceIndex: entry.sourceIndex,
        index,
        name: entry.set?.name ?? `Set ${index + 1}`,
        description: stringOrNull(entry.set?.description),
        job,
        level,
        partyBonus: integerOrNull(entry.set?.partyBonus ?? entry.sheet?.partyBonus),
        race: stringOrNull(entry.set?.race ?? entry.sheet?.race),
        foodId: integerOrNull(entry.set?.food),
        ilvlSync: integerOrNull(entry.set?.ilvlSync ?? entry.sheet?.ilvlSync),
        stats: extractCombatStats(computedStats, level),
        jobStatMultipliers: extractJobStatMultipliers(computedStats),
        gearStats: computedStats.gearStats ? extractCombatStats(computedStats.gearStats, level) : null,
        effectiveFoodBonuses: computedStats.effectiveFoodBonuses
            ? extractCombatStats(computedStats.effectiveFoodBonuses, level, { useLevelDefaults: false })
            : null,
        rawSet: entry.set,
        rawComputedStats: computedStats,
    })
}

function findComputedStats(value) {
    if (value?.computedStats && typeof value.computedStats === 'object') {
        return value.computedStats
    }

    if (value?.computed && typeof value.computed === 'object') {
        return value.computed
    }

    return null
}

function extractCombatStats(stats, level, options = {}) {
    const normalized = {}

    for (const key of STAT_KEYS) {
        normalized[key] = numericStat(stats?.[key])
    }

    for (const [sourceKey, targetKey] of Object.entries(XIV_GEAR_STAT_MAP)) {
        if (normalized[targetKey] === undefined && stats?.[sourceKey] !== undefined) {
            normalized[targetKey] = numericStat(stats[sourceKey])
        }
    }

    if (options.useLevelDefaults === false) {
        return Object.freeze(Object.fromEntries(STAT_KEYS.map(key => [key, normalized[key] ?? 0])))
    }

    return normalizeCombatStats(normalized, getLevelStats(level))
}

function extractJobStatMultipliers(computedStats) {
    const multipliers = computedStats?.jobStats?.jobStatMultipliers
        ?? computedStats?.jobStatMultipliers
        ?? {}

    return Object.freeze({
        hp: numericMultiplier(multipliers.hp),
        vitality: numericMultiplier(multipliers.vitality),
        strength: numericMultiplier(multipliers.strength),
        dexterity: numericMultiplier(multipliers.dexterity),
        intelligence: numericMultiplier(multipliers.intelligence),
        mind: numericMultiplier(multipliers.mind),
    })
}

function normalizeJob(job) {
    return typeof job === 'string' && job.trim() ? job.trim().toUpperCase() : null
}

function numericStat(value) {
    return Number.isFinite(value) ? value : undefined
}

function numericMultiplier(value) {
    return Number.isFinite(value) ? value : undefined
}

function integerOrNull(value) {
    if (Number.isInteger(value)) {
        return value
    }

    if (typeof value === 'string' && value.trim()) {
        const parsed = Number(value)

        return Number.isInteger(parsed) ? parsed : null
    }

    return null
}

function stringOrNull(value) {
    return typeof value === 'string' && value.trim() ? value : null
}

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value)
}
