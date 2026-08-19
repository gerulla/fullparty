import assert from 'node:assert/strict'

import { ATTACK_TYPES, baseDamage, deriveCombatStats } from '../../resources/calculator/math/combatMath.js'
import {
    buildXivGearFoodUrl,
    buildXivGearFulldataUrl,
    buildXivGearItemsUrl,
    buildXivGearMateriaUrl,
    enrichXivGearEquipment,
    extractXivGearEquipment,
    fetchXivGearReferenceData,
    fetchXivGearSet,
    importXivGearPayload,
    importXivGearSet,
    isXivGearFulldataPayload,
    parseXivGearJson,
    toCombatMathConfig,
} from '../../resources/calculator/importers/xivGearImporter.js'

const xivGearUrl = 'https://xivgear.app/?page=sl|9752ab9a-e2aa-43f9-a409-8a4f029b8a8c&onlySetIndex=2'
const apiUrl = new URL(buildXivGearFulldataUrl(xivGearUrl, { onlySetIndex: 1, partyBonus: 5 }))
const defaultApiUrl = new URL(buildXivGearFulldataUrl(xivGearUrl))

assert.equal(apiUrl.origin, 'https://api.xivgear.app')
assert.equal(apiUrl.pathname, '/fulldata')
assert.equal(apiUrl.searchParams.get('url'), xivGearUrl)
assert.equal(apiUrl.searchParams.get('onlySetIndex'), '1')
assert.equal(apiUrl.searchParams.get('partyBonus'), '5')
assert.equal(defaultApiUrl.searchParams.get('partyBonus'), '0')

const itemsUrl = new URL(buildXivGearItemsUrl('pld'))
assert.equal(itemsUrl.origin, 'https://data.xivgear.app')
assert.equal(itemsUrl.pathname, '/Items')
assert.equal(itemsUrl.searchParams.get('job'), 'PLD')
assert.equal(new URL(buildXivGearMateriaUrl()).pathname, '/Materia')
assert.equal(new URL(buildXivGearFoodUrl()).pathname, '/Food')

const sheetPayload = {
    name: 'Tank Sets',
    description: 'A shared XivGear sheet',
    job: 'PLD',
    level: 100,
    partyBonus: 5,
    race: 'Midlander',
    sets: [
        {
            name: 'Divider',
            isSeparator: true,
        },
        {
            name: 'Paladin BiS',
            description: 'A usable set',
            food: 44100,
            items: {
                Weapon: {
                    id: 45001,
                    name: 'Sword of Testing',
                    materia: [
                        { id: 40001, name: 'Savage Aim Materia XII' },
                        { id: 40002, locked: true },
                        { id: -1 },
                    ],
                },
                OffHand: {
                    id: 45002,
                    relicStats: {
                        crit: 36,
                        determination: 36,
                    },
                    materia: [],
                },
            },
            computedStats: {
                level: 100,
                job: 'pld',
                hp: 0,
                vitality: 4936,
                strength: 4899,
                criticalHit: 3367,
                directHit: 1608,
                determination: 2700,
                tenacity: 1305,
                skillspeed: 420,
                spellspeed: 420,
                wdPhys: 146,
                weaponDelay: 2.24,
                defensePhys: 6668,
                defenseMag: 6668,
                jobStats: {
                    jobStatMultipliers: {
                        hp: 120,
                        vitality: 110,
                        strength: 105,
                        dexterity: 95,
                        intelligence: 60,
                        mind: 100,
                    },
                },
                gearStats: {
                    vitality: 4936,
                    strength: 4899,
                    crit: 3225,
                    dhit: 1532,
                    determination: 2615,
                    tenacity: 1305,
                    skillspeed: 420,
                    spellspeed: 420,
                    wdPhys: 146,
                    weaponDelay: 2.24,
                },
                effectiveFoodBonuses: {
                    crit: 142,
                    determination: 85,
                },
            },
        },
    ],
}

assert.equal(isXivGearFulldataPayload(sheetPayload), true)
assert.equal(isXivGearFulldataPayload({ ...sheetPayload, sets: [{ name: 'No stats', items: {} }] }), false)

const importedSheet = importXivGearPayload(JSON.stringify(sheetPayload))
assert.equal(importedSheet.source, 'xivgear')
assert.equal(importedSheet.sheet.name, 'Tank Sets')
assert.equal(importedSheet.sheet.job, 'PLD')
assert.equal(importedSheet.sets.length, 1)

const importedSet = importedSheet.sets[0]
assert.equal(importedSet.sourceIndex, 1)
assert.equal(importedSet.index, 0)
assert.equal(importedSet.name, 'Paladin BiS')
assert.equal(importedSet.job, 'PLD')
assert.equal(importedSet.level, 100)
assert.equal(importedSet.partyBonus, 5)
assert.equal(importedSet.foodId, 44100)
assert.equal(importedSet.stats.crit, 3367)
assert.equal(importedSet.stats.dhit, 1608)
assert.equal(importedSet.stats.piety, 440)
assert.equal(importedSet.jobStatMultipliers.hp, 120)
assert.equal(importedSet.jobStatMultipliers.strength, 105)
assert.equal(importedSet.gearStats.crit, 3225)
assert.equal(importedSet.effectiveFoodBonuses.crit, 142)
assert.equal(importedSet.effectiveFoodBonuses.piety, 0)

const equipment = extractXivGearEquipment(importedSet)
assert.equal(equipment.length, 3)
assert.deepEqual(
    equipment.map(item => item.slot),
    ['Weapon', 'OffHand', 'Food'],
)
assert.equal(equipment[0].label, 'Weapon')
assert.equal(equipment[0].id, 45001)
assert.equal(equipment[0].name, 'Sword of Testing')
assert.equal(equipment[0].materia.length, 2)
assert.equal(equipment[0].materia[0].name, 'Savage Aim Materia XII')
assert.equal(equipment[0].materia[1].locked, true)
assert.deepEqual(equipment[1].relicStats, { crit: 36, determination: 36 })
assert.equal(equipment[2].id, 44100)

const referenceRequests = []
const referenceData = await fetchXivGearReferenceData('pld', {
    dataApiBaseUrl: 'https://data.example.test',
    fetch: async (url) => {
        const parsed = new URL(url)
        referenceRequests.push(parsed)

        if (parsed.pathname === '/Items') {
            assert.equal(parsed.searchParams.get('job'), 'PLD')

            return responseJson({
                items: [
                    {
                        primaryKey: 45001,
                        rowId: 45001,
                        name: 'Sword of Testing',
                        nameTranslations: {
                            en: 'Testing Sword',
                            de: 'Pruefschwert',
                        },
                        icon: {
                            url: 'https://cdn.example.test/sword.png',
                        },
                    },
                    {
                        primaryKey: 45002,
                        rowId: 45002,
                        name: 'Shield of Testing',
                    },
                ],
            })
        }

        if (parsed.pathname === '/Materia') {
            return responseJson({
                items: [
                    {
                        baseParam: 27,
                        item: [
                            {
                                primaryKey: 40001,
                                rowId: 40001,
                                name: 'Savage Aim Materia XII',
                                nameTranslations: {
                                    en: 'Savage Aim Materia XII',
                                    de: 'Krit-Materia XII',
                                },
                                icon: {
                                    url: 'https://cdn.example.test/crit-materia.png',
                                },
                            },
                            {
                                primaryKey: 40002,
                                rowId: 40002,
                                name: 'Heavens Eye Materia XII',
                                nameTranslations: {
                                    en: 'Heavens Eye Materia XII',
                                    de: 'DH-Materia XII',
                                },
                            },
                        ],
                    },
                ],
            })
        }

        if (parsed.pathname === '/Food') {
            return responseJson({
                items: [
                    {
                        primaryKey: 44100,
                        rowId: 44100,
                        foodItemId: 99001,
                        name: 'Caramel Popcorn',
                        nameTranslations: {
                            en: 'Caramel Popcorn',
                            de: 'Karamellpopcorn',
                        },
                        icon: {
                            url: 'https://cdn.example.test/popcorn.png',
                        },
                    },
                ],
            })
        }

        throw new Error(`Unexpected reference request: ${url}`)
    },
})

assert.equal(referenceRequests.length, 3)
assert.equal(referenceData.itemsById.get(45001).name, 'Sword of Testing')
assert.equal(referenceData.materiaById.get(40002).name, 'Heavens Eye Materia XII')
assert.equal(referenceData.foodById.get(99001).name, 'Caramel Popcorn')

const enrichedEquipment = enrichXivGearEquipment(equipment, referenceData, { locale: 'de' })
assert.equal(enrichedEquipment[0].name, 'Pruefschwert')
assert.equal(enrichedEquipment[0].iconUrl, 'https://cdn.example.test/sword.png')
assert.equal(enrichedEquipment[0].materia[0].name, 'Krit-Materia XII')
assert.equal(enrichedEquipment[0].materia[0].iconUrl, 'https://cdn.example.test/crit-materia.png')
assert.equal(enrichedEquipment[0].materia[1].name, 'DH-Materia XII')
assert.equal(enrichedEquipment[2].name, 'Karamellpopcorn')
assert.equal(enrichedEquipment[2].iconUrl, 'https://cdn.example.test/popcorn.png')

const selectedSet = importXivGearSet(sheetPayload)
assert.equal(selectedSet.name, 'Paladin BiS')

const combatConfig = toCombatMathConfig(selectedSet)
assert.deepEqual(combatConfig, {
    level: 100,
    job: 'PLD',
    stats: selectedSet.stats,
    jobStatMultipliers: selectedSet.jobStatMultipliers,
})

const derivedStats = deriveCombatStats(combatConfig)
assert.equal(derivedStats.mainStatMultiplier, 20.25)
assert.equal(derivedStats.weaponDamageMultiplier, 1.92)
assert.equal(baseDamage(derivedStats, { potency: 220, attackType: ATTACK_TYPES.WEAPONSKILL }), 9851)

const standaloneSet = {
    name: 'Standalone Black Mage',
    job: 'BLM',
    level: 100,
    computedStats: {
        level: 100,
        job: 'BLM',
        intelligence: 4899,
        vitality: 4936,
        crit: 420,
        dhit: 420,
        determination: 2700,
        tenacity: 420,
        skillspeed: 420,
        spellspeed: 420,
        wdMag: 146,
        weaponDelay: 3.28,
        jobStatMultipliers: {
            hp: 105,
            intelligence: 115,
            strength: 100,
        },
    },
}

assert.equal(importXivGearSet(standaloneSet).job, 'BLM')
assert.deepEqual(parseXivGearJson({ ok: true }), { ok: true })
assert.throws(
    () => importXivGearSet({ name: 'Base-only set', job: 'PLD', level: 100, items: {} }),
    /missing computedStats/,
)
assert.throws(
    () => parseXivGearJson(xivGearUrl),
    /must be parsed JSON/,
)

let requestedUrl = null
const fetchedSet = await fetchXivGearSet(xivGearUrl, {
    onlySetIndex: 1,
    fetch: async (url) => {
        requestedUrl = url

        return {
            ok: true,
            status: 200,
            json: async () => sheetPayload,
        }
    },
})

assert.equal(fetchedSet.name, 'Paladin BiS')
assert.equal(new URL(requestedUrl).searchParams.get('onlySetIndex'), '1')
assert.equal(new URL(requestedUrl).searchParams.get('url'), xivGearUrl)
assert.equal(new URL(requestedUrl).searchParams.get('partyBonus'), '0')

await assert.rejects(
    () => fetchXivGearSet(xivGearUrl, {
        fetch: async () => ({
            ok: false,
            status: 503,
            text: async () => 'try again later',
        }),
    }),
    /status 503: try again later/,
)

function responseJson(payload) {
    return {
        ok: true,
        status: 200,
        json: async () => payload,
    }
}

console.log('xivgear importer validation ok')
