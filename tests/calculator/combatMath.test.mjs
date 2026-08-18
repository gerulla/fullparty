import assert from 'node:assert/strict'

import {
    ATTACK_TYPES,
    addValues,
    applyStdDev,
    baseDamage,
    baseDamageFull,
    ceilPrecision,
    ceilXiv,
    chanceMultiplierStdDev,
    combineHasteBuffs,
    combineHasteTypes,
    critChance,
    critDamageMultiplier,
    deriveCombatStats,
    determinationDamageMultiplier,
    directHitChance,
    floorPrecision,
    floorXiv,
    getLevelStats,
    skillSpeedToGcd,
    spellSpeedToGcd,
    tenacityDamageMultiplier,
    tenacityIncomingDamageMultiplier,
} from '../../resources/calculator/math/combatMath.js'

function closeTo(actual, expected, tolerance = 0.0000001) {
    assert.ok(
        Math.abs(actual - expected) <= tolerance,
        `expected ${actual} to be within ${tolerance} of ${expected}`,
    )
}

assert.equal(floorXiv(5.5), 5)
assert.equal(2.3 * 100, 229.99999999999997)
assert.equal(Math.floor(2.3 * 100), 229)
assert.equal(floorXiv(2.3 * 100), 230)
assert.equal(floorPrecision(3, 5.56789), 5.567)
assert.equal(floorPrecision(3, 2.3 * 100), 230)
assert.equal(ceilXiv(5.5), 6)
assert.equal(1.1 * 100, 110.00000000000001)
assert.equal(Math.ceil(1.1 * 100), 111)
assert.equal(ceilXiv(1.1 * 100), 110)
assert.equal(ceilPrecision(3, 5.56789), 5.568)
assert.equal(ceilPrecision(3, 1.1 * 100), 110)

const level100 = getLevelStats(100)
assert.deepEqual(
    {
        level: level100.level,
        baseMainStat: level100.baseMainStat,
        baseSubStat: level100.baseSubStat,
        levelDiv: level100.levelDiv,
        hp: level100.hp,
    },
    {
        level: 100,
        baseMainStat: 440,
        baseSubStat: 420,
        levelDiv: 2780,
        hp: 4000,
    },
)

assert.equal(skillSpeedToGcd(2.5, level100, 420), 2.5)
assert.equal(spellSpeedToGcd(2.5, level100, 420), 2.5)
assert.equal(skillSpeedToGcd(2.5, level100, 676), 2.47)
assert.equal(skillSpeedToGcd(2.5, level100, 420, 15), 2.12)
assert.equal(combineHasteTypes(15, 0, 20, 0), 32)
assert.equal(combineHasteBuffs(15, 20), 32)

assert.equal(critChance(level100, 420), 0.05)
assert.equal(critChance(level100, 3367), 0.262)
assert.equal(critDamageMultiplier(level100, 3367), 1.612)
assert.equal(directHitChance(level100, 420), 0)
assert.equal(directHitChance(level100, 1608), 0.235)
assert.equal(determinationDamageMultiplier(level100, 440), 1)
assert.equal(determinationDamageMultiplier(level100, 2700), 1.113)
assert.equal(tenacityDamageMultiplier(level100, 420), 1)
assert.equal(tenacityDamageMultiplier(level100, 1305), 1.035)
assert.equal(tenacityIncomingDamageMultiplier(level100, 1305), 0.937)

const paladin = deriveCombatStats({
    level: 100,
    job: 'PLD',
    stats: {
        strength: 4899,
        vitality: 4936,
        crit: 3367,
        dhit: 1608,
        determination: 2700,
        tenacity: 1305,
        skillspeed: 420,
        spellspeed: 420,
        wdPhys: 146,
        weaponDelay: 2.24,
        defensePhys: 6668,
        defenseMag: 6668,
    },
    jobStatMultipliers: {
        hp: 120,
        strength: 105,
    },
})

assert.equal(paladin.mainStatMultiplier, 20.25)
assert.equal(paladin.weaponDamageMultiplier, 1.92)
assert.equal(paladin.autoAttackMultiplier, 1.43)
assert.equal(paladin.critChance, 0.262)
assert.equal(paladin.directHitChance, 0.235)
assert.equal(paladin.determinationMultiplier, 1.113)
assert.equal(paladin.tenacityMultiplier, 1.035)
assert.equal(baseDamage(paladin, { potency: 220, attackType: ATTACK_TYPES.WEAPONSKILL }), 9851)

const paladinDamage = baseDamageFull(paladin, { potency: 220, attackType: ATTACK_TYPES.WEAPONSKILL })
assert.equal(paladinDamage.expected, 9851)
closeTo(paladinDamage.stdDev, 284.3738750893502)

const whiteMage = deriveCombatStats({
    level: 100,
    job: 'WHM',
    stats: {
        mind: 4899,
        strength: 440,
        vitality: 4936,
        crit: 420,
        dhit: 420,
        determination: 2700,
        tenacity: 420,
        skillspeed: 420,
        spellspeed: 420,
        wdMag: 146,
        weaponDelay: 3.44,
    },
    jobStatMultipliers: {
        hp: 105,
        mind: 115,
        strength: 100,
    },
})

assert.equal(whiteMage.mainStatMultiplier, 25.01)
assert.equal(whiteMage.weaponDamageMultiplier, 1.96)
assert.equal(baseDamage(whiteMage, { potency: 400, attackType: ATTACK_TYPES.SPELL }), 28363)

const chance = chanceMultiplierStdDev(0.25, 1.5)
assert.equal(chance.expected, 1.125)
closeTo(chance.stdDev, 0.21650635094610965)

const sum = addValues({ expected: 3, stdDev: 0.5 }, { expected: 5, stdDev: 1 })
assert.equal(sum.expected, 8)
closeTo(sum.stdDev, Math.sqrt(1.25))
assert.equal(applyStdDev({ expected: 100, stdDev: 15 }, -1), 85)

console.log('combat math validation ok')
