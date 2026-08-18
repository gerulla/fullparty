// FFXIV combat math helpers aligned with XivGear/AkhMorning-style level/stat formulas.
const FLOATING_POINT_MARGIN = 0.99999995

export const ATTACK_TYPES = Object.freeze({
    ABILITY: 'Ability',
    AUTO_ATTACK: 'Auto-attack',
    SPELL: 'Spell',
    UNKNOWN: 'Unknown',
    WEAPONSKILL: 'Weaponskill',
})

export const SUPPORTED_LEVELS = Object.freeze([50, 60, 70, 80, 90, 100])

export const LEVEL_STATS = Object.freeze({
    50: Object.freeze({
        level: 50,
        baseMainStat: 202,
        baseSubStat: 341,
        levelDiv: 341,
        hp: 1700,
        hpScalar: Object.freeze({ Tank: 14.5, other: 10.8 }),
        mainStatPowerMod: Object.freeze({ Tank: 56, other: 75 }),
    }),
    60: Object.freeze({
        level: 60,
        baseMainStat: 218,
        baseSubStat: 354,
        levelDiv: 600,
        hp: 1700,
        hpScalar: Object.freeze({ Tank: 16, other: 12 }),
        mainStatPowerMod: Object.freeze({ Tank: 91, other: 114 }),
    }),
    70: Object.freeze({
        level: 70,
        baseMainStat: 292,
        baseSubStat: 364,
        levelDiv: 900,
        hp: 1700,
        hpScalar: Object.freeze({ Tank: 18.8, other: 14 }),
        mainStatPowerMod: Object.freeze({ Tank: 105, other: 125 }),
    }),
    80: Object.freeze({
        level: 80,
        baseMainStat: 340,
        baseSubStat: 380,
        levelDiv: 1300,
        hp: 2000,
        hpScalar: Object.freeze({ Tank: 26.6, other: 18.8 }),
        mainStatPowerMod: Object.freeze({ Tank: 115, other: 165 }),
    }),
    90: Object.freeze({
        level: 90,
        baseMainStat: 390,
        baseSubStat: 400,
        levelDiv: 1900,
        hp: 3000,
        hpScalar: Object.freeze({ Tank: 34.6, other: 24.3 }),
        mainStatPowerMod: Object.freeze({ Tank: 156, other: 195 }),
    }),
    100: Object.freeze({
        level: 100,
        baseMainStat: 440,
        baseSubStat: 420,
        levelDiv: 2780,
        hp: 4000,
        hpScalar: Object.freeze({ Tank: 43, other: 30.1 }),
        mainStatPowerMod: Object.freeze({ Tank: 190, other: 237 }),
    }),
})

export const STAT_KEYS = Object.freeze([
    'hp',
    'vitality',
    'strength',
    'dexterity',
    'intelligence',
    'mind',
    'piety',
    'crit',
    'dhit',
    'determination',
    'tenacity',
    'spellspeed',
    'skillspeed',
    'wdPhys',
    'wdMag',
    'weaponDelay',
    'defenseMag',
    'defensePhys',
    'gearHaste',
    'extraMainStat',
    'extraSecondaryStat',
])

export const DEFAULT_JOB_STAT_MULTIPLIERS = Object.freeze({
    hp: 100,
    vitality: 100,
    strength: 100,
    dexterity: 100,
    intelligence: 100,
    mind: 100,
})

const MELEE_AUTO_POTENCY = 90
const RANGED_AUTO_POTENCY = 80

const JOBS = {
    PLD: standardTank('Paladin', { offhand: true }),
    WAR: standardTank('Warrior'),
    DRK: standardTank('Dark Knight'),
    GNB: standardTank('Gunbreaker'),
    WHM: standardHealer('White Mage'),
    SCH: standardHealer('Scholar'),
    AST: standardHealer('Astrologian'),
    SGE: standardHealer('Sage'),
    MNK: standardMelee('Monk', 'strength', 'Striking', {
        traitHaste: (level) => {
            if (level >= 76) {
                return 20
            }

            if (level >= 40) {
                return 15
            }

            if (level >= 20) {
                return 10
            }

            return 5
        },
    }),
    DRG: standardMelee('Dragoon', 'strength', 'Maiming'),
    NIN: standardMelee('Ninja', 'dexterity', 'Scouting', { traitHaste: () => 15 }),
    SAM: standardMelee('Samurai', 'strength', 'Striking'),
    RPR: standardMelee('Reaper', 'strength', 'Maiming'),
    VPR: standardMelee('Viper', 'dexterity', 'Scouting'),
    BRD: standardRanged('Bard'),
    MCH: standardRanged('Machinist'),
    DNC: standardRanged('Dancer', { aaPotency: MELEE_AUTO_POTENCY }),
    BLM: standardCaster('Black Mage'),
    SMN: standardCaster('Summoner'),
    RDM: standardCaster('Red Mage'),
    PCT: standardCaster('Pictomancer'),
    BLU: standardCaster('Blue Mage', {
        minLevel: 50,
        maxLevel: 80,
        traitDamageMultiplier: (attackType) => attackType === ATTACK_TYPES.AUTO_ATTACK ? 1 : 1.5,
    }),
}

export const COMBAT_JOBS = deepFreeze(JOBS)

export function floorXiv(input) {
    const floored = Math.floor(input)
    const loss = input - floored

    return loss >= FLOATING_POINT_MARGIN ? floored + 1 : floored
}

export function ceilXiv(input) {
    const ceiled = Math.ceil(input)
    const loss = ceiled - input

    return loss >= FLOATING_POINT_MARGIN ? ceiled - 1 : ceiled
}

export function truncateXiv(input) {
    if (input > 0) {
        return floorXiv(input)
    }

    if (input < 0) {
        return -floorXiv(-input)
    }

    return 0
}

export function floorPrecision(places, input) {
    assertPrecisionPlaces(places)

    const multiplier = 10 ** places

    return floorXiv(input * multiplier) / multiplier
}

export function ceilPrecision(places, input) {
    assertPrecisionPlaces(places)

    const multiplier = 10 ** places

    return ceilXiv(input * multiplier) / multiplier
}

export function getLevelStats(level) {
    const stats = LEVEL_STATS[level]

    if (!stats) {
        throw new Error(`Unsupported combat level: ${level}`)
    }

    return stats
}

export function getCombatJob(job) {
    const jobData = COMBAT_JOBS[job]

    if (!jobData) {
        throw new Error(`Unsupported combat job: ${job}`)
    }

    return jobData
}

export function skillSpeedToGcd(baseGcd, levelStats, skillSpeed, haste = 0) {
    return Math.max(
        0,
        floorXiv(
            (floorXiv((1000 - floorXiv(130 * (skillSpeed - levelStats.baseSubStat) / levelStats.levelDiv)) * baseGcd)
                * (100 - haste))
            / 1000,
        ) / 100,
    )
}

export function spellSpeedToGcd(baseGcd, levelStats, spellSpeed, haste = 0) {
    return skillSpeedToGcd(baseGcd, levelStats, spellSpeed, haste)
}

export function critChance(levelStats, crit) {
    return floorXiv(200 * (crit - levelStats.baseSubStat) / levelStats.levelDiv + 50) / 1000
}

export function critDamageMultiplier(levelStats, crit) {
    return (1400 + floorXiv(200 * (crit - levelStats.baseSubStat) / levelStats.levelDiv)) / 1000
}

export function directHitChance(levelStats, directHit) {
    return floorXiv(550 * (directHit - levelStats.baseSubStat) / levelStats.levelDiv) / 1000
}

export function directHitDamageMultiplier() {
    return 1.25
}

export function determinationDamageMultiplier(levelStats, determination) {
    return (1000 + floorXiv(140 * (determination - levelStats.baseMainStat) / levelStats.levelDiv)) / 1000
}

export function weaponDamageMultiplier(levelStats, jobData, weaponDamage, options = {}) {
    const jobStatMultipliers = options.jobStatMultipliers ?? DEFAULT_JOB_STAT_MULTIPLIERS
    const mainStat = jobData.mainStat
    const jobMultiplier = options.petAction ? 100 : resolveJobStatMultiplier(jobStatMultipliers, mainStat)

    return floorXiv(levelStats.baseMainStat * jobMultiplier / 1000 + weaponDamage) / 100
}

export function spellSpeedTickMultiplier(levelStats, spellSpeed) {
    return (1000 + floorXiv(130 * (spellSpeed - levelStats.baseSubStat) / levelStats.levelDiv)) / 1000
}

export function skillSpeedTickMultiplier(levelStats, skillSpeed) {
    return spellSpeedTickMultiplier(levelStats, skillSpeed)
}

export function mainStatMultiplier(levelStats, jobData, mainStatValue) {
    const powerModifier = mainStatPowerModifier(levelStats, jobData)

    return Math.max(
        0,
        (truncateXiv(powerModifier * (mainStatValue - levelStats.baseMainStat) / levelStats.baseMainStat) + 100) / 100,
    )
}

export function tenacityDamageMultiplier(levelStats, tenacity) {
    return (1000 + floorXiv(112 * (tenacity - levelStats.baseSubStat) / levelStats.levelDiv)) / 1000
}

export function defenseIncomingDamageMultiplier(levelStats, defense) {
    return Math.max(0, (100 - floorXiv(15 * defense / levelStats.levelDiv)) / 100)
}

export function tenacityIncomingDamageMultiplier(levelStats, tenacity) {
    return (1000 - floorXiv(200 * (tenacity - levelStats.baseSubStat) / levelStats.levelDiv)) / 1000
}

export function autoDirectHitBonusDamage(levelStats, directHit) {
    return floorXiv(140 * ((directHit - levelStats.baseSubStat) / levelStats.levelDiv)) / 1000
}

export function autoCritBuffDamageMultiplier(critMultiplier, bonusCritChance) {
    return floorPrecision(3, 1 + ((critMultiplier - 1) * bonusCritChance))
}

export function autoDirectHitBuffDamageMultiplier(directHitMultiplier, bonusDirectHitChance) {
    return floorPrecision(3, 1 + ((directHitMultiplier - 1) * bonusDirectHitChance))
}

export function mpPerTick(levelStats, piety) {
    return 200 + floorXiv(150 * (piety - levelStats.baseMainStat) / levelStats.levelDiv)
}

export function autoAttackModifier(levelStats, jobData, weaponDelay, weaponDamage, options = {}) {
    const jobStatMultipliers = options.jobStatMultipliers ?? DEFAULT_JOB_STAT_MULTIPLIERS
    const autoAttackStat = jobData.autoAttackStat
    const jobMultiplier = resolveJobStatMultiplier(jobStatMultipliers, autoAttackStat)

    return floorXiv(floorXiv(levelStats.baseMainStat * jobMultiplier / 1000 + weaponDamage) * (weaponDelay / 3)) / 100
}

export function hpScalar(levelStats, jobData) {
    return levelStats.hpScalar[jobData.combatRole] ?? levelStats.hpScalar.other
}

export function vitalityToHp(levelStats, jobData, vitality, options = {}) {
    const jobStatMultipliers = options.jobStatMultipliers ?? DEFAULT_JOB_STAT_MULTIPLIERS
    const hpJobMultiplier = resolveJobStatMultiplier(jobStatMultipliers, 'hp')

    return floorXiv(levelStats.hp * hpJobMultiplier / 100)
        + floorXiv((vitality - levelStats.baseMainStat) * hpScalar(levelStats, jobData))
}

export function mainStatPowerModifier(levelStats, jobData) {
    return levelStats.mainStatPowerMod[jobData.combatRole] ?? levelStats.mainStatPowerMod.other
}

export function combineHasteTypes(buffHaste, gearHaste, traitHaste, gaugeHaste) {
    const buffHasteMultiplier = floorPrecision(2, (100 - buffHaste) / 100)
    const gaugeHasteMultiplier = floorPrecision(2, (100 - gaugeHaste) / 100)
    const gearHasteMultiplier = floorPrecision(2, (100 - gearHaste) / 100)
    const traitHasteMultiplier = floorPrecision(2, (100 - traitHaste) / 100)
    const combined = floorPrecision(
        2,
        ceilPrecision(2, floorPrecision(2, buffHasteMultiplier * traitHasteMultiplier) * gaugeHasteMultiplier)
            * gearHasteMultiplier,
    )

    return floorXiv((1 - combined) * 100)
}

export function combineHasteBuffs(existingHaste, nextBuffHaste) {
    const existingMultiplier = floorPrecision(2, (100 - existingHaste) / 100)
    const nextMultiplier = floorPrecision(2, (100 - nextBuffHaste) / 100)
    const combinedMultiplier = floorPrecision(2, existingMultiplier * nextMultiplier)

    return floorXiv(100 * (1 - combinedMultiplier))
}

export function normalizeCombatStats(stats = {}, levelStats = getLevelStats(100)) {
    return Object.freeze({
        hp: numberOr(stats.hp, 0),
        vitality: numberOr(stats.vitality, 0),
        strength: numberOr(stats.strength, 0),
        dexterity: numberOr(stats.dexterity, 0),
        intelligence: numberOr(stats.intelligence, 0),
        mind: numberOr(stats.mind, 0),
        piety: numberOr(stats.piety, stats.pie, levelStats.baseMainStat),
        crit: numberOr(stats.crit, stats.criticalHit, levelStats.baseSubStat),
        dhit: numberOr(stats.dhit, stats.directHit, stats.directHitRate, levelStats.baseSubStat),
        determination: numberOr(stats.determination, stats.det, levelStats.baseMainStat),
        tenacity: numberOr(stats.tenacity, stats.ten, levelStats.baseSubStat),
        spellspeed: numberOr(stats.spellspeed, stats.spellSpeed, stats.sps, levelStats.baseSubStat),
        skillspeed: numberOr(stats.skillspeed, stats.skillSpeed, stats.sks, levelStats.baseSubStat),
        wdPhys: numberOr(stats.wdPhys, stats.physicalWeaponDamage, stats.weaponDamage, 0),
        wdMag: numberOr(stats.wdMag, stats.magicWeaponDamage, stats.weaponDamage, 0),
        weaponDelay: numberOr(stats.weaponDelay, 0),
        defenseMag: numberOr(stats.defenseMag, stats.magicDefense, 0),
        defensePhys: numberOr(stats.defensePhys, stats.defense, 0),
        gearHaste: numberOr(stats.gearHaste, 0),
        extraMainStat: numberOr(stats.extraMainStat, 0),
        extraSecondaryStat: numberOr(stats.extraSecondaryStat, 0),
    })
}

export function deriveCombatStats(config) {
    const levelStats = getLevelStats(config.level ?? 100)
    const jobData = config.jobData
        ? normalizeJobData(config.jobData)
        : getCombatJob(config.job)
    const rawStats = normalizeCombatStats(config.stats, levelStats)
    const finalBonuses = normalizeFinalBonuses(config.finalBonuses)
    const jobStatMultipliers = {
        ...DEFAULT_JOB_STAT_MULTIPLIERS,
        ...(jobData.jobStatMultipliers ?? {}),
        ...(config.jobStatMultipliers ?? {}),
    }
    const mainStatValue = rawStats[jobData.mainStat]
    const autoAttackStatValue = rawStats[jobData.autoAttackStat]
    const weaponDamage = Math.max(rawStats.wdPhys, rawStats.wdMag)
    const baseCritChance = clamp(0, 1, critChance(levelStats, rawStats.crit))
    const baseDirectHitChance = clamp(0, 1, directHitChance(levelStats, rawStats.dhit))
    const critMultiplier = critDamageMultiplier(levelStats, rawStats.crit) + finalBonuses.critDamage
    const dhitMultiplier = directHitDamageMultiplier() + finalBonuses.directHitDamage
    const derived = {
        level: levelStats.level,
        levelStats,
        job: config.job ?? jobData.abbreviation ?? null,
        jobData,
        rawStats,
        finalBonuses,
        jobStatMultipliers,
        mainStatValue,
        autoAttackStatValue,
        critChance: finalBonuses.forceCrit ? 1 : clamp(0, 1, baseCritChance + finalBonuses.critChance),
        baseCritChance,
        critMultiplier,
        directHitChance: finalBonuses.forceDirectHit
            ? 1
            : clamp(0, 1, baseDirectHitChance + finalBonuses.directHitChance),
        baseDirectHitChance,
        directHitMultiplier: dhitMultiplier,
        determinationMultiplier: determinationDamageMultiplier(levelStats, rawStats.determination)
            + finalBonuses.determinationMultiplier,
        tenacityMultiplier: tenacityDamageMultiplier(levelStats, rawStats.tenacity),
        tenacityIncomingMultiplier: tenacityIncomingDamageMultiplier(levelStats, rawStats.tenacity),
        skillSpeedDotMultiplier: skillSpeedTickMultiplier(levelStats, rawStats.skillspeed),
        spellSpeedDotMultiplier: spellSpeedTickMultiplier(levelStats, rawStats.spellspeed),
        mainStatMultiplier: mainStatMultiplier(levelStats, jobData, mainStatValue),
        weaponDamageMultiplier: weaponDamageMultiplier(levelStats, jobData, weaponDamage, { jobStatMultipliers }),
        autoAttackStatMultiplier: mainStatMultiplier(levelStats, jobData, autoAttackStatValue),
        autoAttackMultiplier: autoAttackModifier(levelStats, jobData, rawStats.weaponDelay, rawStats.wdPhys, {
            jobStatMultipliers,
        }),
        autoDirectHitBonus: autoDirectHitBonusDamage(levelStats, rawStats.dhit),
        mpPerTick: mpPerTick(levelStats, rawStats.piety),
        hp: rawStats.hp + vitalityToHp(levelStats, jobData, rawStats.vitality, { jobStatMultipliers }),
        defenseIncomingMultiplier: defenseIncomingDamageMultiplier(levelStats, rawStats.defensePhys),
        magicDefenseIncomingMultiplier: defenseIncomingDamageMultiplier(levelStats, rawStats.defenseMag),
    }

    derived.autoCritBuffMultiplier = autoCritBuffDamageMultiplier(
        derived.critMultiplier,
        finalBonuses.critChance,
    )
    derived.autoDirectHitBuffMultiplier = autoDirectHitBuffDamageMultiplier(
        derived.directHitMultiplier,
        finalBonuses.directHitChance,
    )
    derived.traitMultiplier = (attackType = ATTACK_TYPES.UNKNOWN) => {
        if (typeof jobData.traitDamageMultiplier === 'function') {
            return jobData.traitDamageMultiplier(attackType, levelStats.level)
        }

        return 1
    }
    derived.traitHaste = (attackType = ATTACK_TYPES.UNKNOWN) => {
        if (!isHasteAffectedAttackType(attackType) || typeof jobData.traitHaste !== 'function') {
            return 0
        }

        return jobData.traitHaste(levelStats.level, attackType)
    }
    derived.haste = (attackType = ATTACK_TYPES.UNKNOWN, buffHaste = 0, gaugeHaste = 0) => combineHasteTypes(
        buffHaste,
        rawStats.gearHaste,
        derived.traitHaste(attackType),
        gaugeHaste,
    )
    derived.physicalGcd = (baseGcd = 2.5, haste = 0) => skillSpeedToGcd(
        baseGcd,
        levelStats,
        rawStats.skillspeed,
        haste,
    )
    derived.magicalGcd = (baseGcd = 2.5, haste = 0) => spellSpeedToGcd(
        baseGcd,
        levelStats,
        rawStats.spellspeed,
        haste,
    )

    return Object.freeze(derived)
}

export function defaultScalingOverrides(derivedStats) {
    return Object.freeze({
        mainStatMultiplier: derivedStats.mainStatMultiplier,
        weaponDamageMultiplier: derivedStats.weaponDamageMultiplier,
        addSkillSpeedMultiplier: false,
    })
}

export function baseDamage(derivedStats, options) {
    return baseDamageFull(derivedStats, options).expected
}

export function baseDamageFull(derivedStats, options) {
    const potency = options.potency

    if (!Number.isFinite(potency)) {
        throw new Error('baseDamageFull requires a finite potency value')
    }

    const attackType = options.attackType ?? ATTACK_TYPES.UNKNOWN
    const autoDirectHit = options.autoDirectHit ?? false
    const isDot = options.isDot ?? false
    const scalingOverrides = {
        ...defaultScalingOverrides(derivedStats),
        ...(options.scalingOverrides ?? {}),
    }
    let speedMultiplier = 1

    if (attackType === ATTACK_TYPES.AUTO_ATTACK) {
        speedMultiplier = derivedStats.skillSpeedDotMultiplier
    } else if (isDot) {
        speedMultiplier = attackType === ATTACK_TYPES.WEAPONSKILL
            ? derivedStats.skillSpeedDotMultiplier
            : derivedStats.spellSpeedDotMultiplier
    } else if (scalingOverrides.addSkillSpeedMultiplier) {
        speedMultiplier = derivedStats.skillSpeedDotMultiplier
    }

    let statMultiplier = scalingOverrides.mainStatMultiplier
    let weaponMultiplier = scalingOverrides.weaponDamageMultiplier

    if (attackType === ATTACK_TYPES.AUTO_ATTACK) {
        statMultiplier = derivedStats.autoAttackStatMultiplier
        weaponMultiplier = derivedStats.autoAttackMultiplier
    }

    const detAutoDirectHitMultiplier = floorPrecision(
        3,
        derivedStats.determinationMultiplier + derivedStats.autoDirectHitBonus,
    )
    const effectiveDeterminationMultiplier = autoDirectHit
        ? detAutoDirectHitMultiplier
        : derivedStats.determinationMultiplier
    const traitMultiplier = derivedStats.traitMultiplier(attackType)
    let stagedPotency

    if (usesCasterDamageFormula(derivedStats, attackType)) {
        const attackPowerDetermination = floorPrecision(2, statMultiplier * effectiveDeterminationMultiplier)
        const basePotency = floorXiv(attackPowerDetermination * floorXiv(weaponMultiplier * potency))
        const afterTenacity = floorXiv(basePotency * derivedStats.tenacityMultiplier)

        stagedPotency = floorXiv(afterTenacity * speedMultiplier)
    } else {
        const basePotency = floorXiv(potency * statMultiplier)
        const afterDetermination = floorXiv(basePotency * effectiveDeterminationMultiplier)
        const afterTenacity = floorXiv(afterDetermination * derivedStats.tenacityMultiplier)
        const afterWeaponDamage = floorXiv(afterTenacity * weaponMultiplier)

        stagedPotency = floorXiv(afterWeaponDamage * speedMultiplier)
    }

    const finalDamage = floorXiv(stagedPotency * traitMultiplier) + (potency < 100 ? 1 : 0)

    if (finalDamage <= 1) {
        return fixedValue(1)
    }

    return {
        expected: finalDamage,
        stdDev: Math.sqrt(0.01 / 12) * finalDamage,
    }
}

export function baseHealing(derivedStats, options) {
    const potency = options.potency

    if (!Number.isFinite(potency)) {
        throw new Error('baseHealing requires a finite potency value')
    }

    const attackType = options.attackType ?? ATTACK_TYPES.UNKNOWN
    const autoCrit = options.autoCrit ?? false
    const basePotency = floorXiv(potency * derivedStats.mainStatMultiplier * 100) / 100
    const afterDetermination = floorXiv(basePotency * derivedStats.determinationMultiplier * 100) / 100
    const afterTenacity = floorXiv(afterDetermination * derivedStats.tenacityMultiplier * 100) / 100
    const afterWeaponDamage = floorXiv(afterTenacity * derivedStats.weaponDamageMultiplier)
    const afterAutoCrit = autoCrit
        ? floorXiv(afterWeaponDamage * (1 + (derivedStats.critChance * (derivedStats.critMultiplier - 1))))
        : afterWeaponDamage
    const afterTrait = floorXiv(floorXiv(afterAutoCrit * derivedStats.traitMultiplier(attackType)) / 100)

    return afterTrait <= 0 ? 1 : afterTrait
}

export function calculateExpectedDamage(derivedStats, options) {
    return applyCriticalDirectHit(
        baseDamageFull(derivedStats, options),
        derivedStats,
        {
            forcedCrit: options.forcedCrit ?? false,
            forcedDirectHit: options.forcedDirectHit ?? options.autoDirectHit ?? false,
        },
    )
}

export function applyCriticalDirectHit(baseValue, derivedStats, options = {}) {
    return multiplyValues(
        baseValue,
        criticalDirectHitMultiplier(derivedStats, options),
    )
}

export function criticalDirectHitMultiplier(derivedStats, options = {}) {
    const forcedCrit = options.forcedCrit ?? false
    const forcedDirectHit = options.forcedDirectHit ?? false
    const critValue = forcedCrit
        ? chanceMultiplierStdDev(1, derivedStats.critMultiplier * derivedStats.autoCritBuffMultiplier)
        : chanceMultiplierStdDev(derivedStats.critChance, derivedStats.critMultiplier)
    const directHitValue = forcedDirectHit
        ? chanceMultiplierStdDev(1, derivedStats.directHitMultiplier * derivedStats.autoDirectHitBuffMultiplier)
        : chanceMultiplierStdDev(derivedStats.directHitChance, derivedStats.directHitMultiplier)

    return multiplyValues(critValue, directHitValue)
}

export function applyCrit(baseDamageValue, derivedStats) {
    return baseDamageValue * (1 + derivedStats.critChance * (derivedStats.critMultiplier - 1))
}

export function fixedValue(value) {
    return Object.freeze({
        expected: value,
        stdDev: 0,
    })
}

export function addValues(...values) {
    let expected = 0
    let variance = 0

    for (const value of values) {
        expected += value.expected
        variance += value.stdDev ** 2
    }

    return {
        expected,
        stdDev: Math.sqrt(variance),
    }
}

export function multiplyValues(...values) {
    let expected = 1
    let firstTerm = 1
    let secondTerm = 1

    for (const value of values) {
        expected *= value.expected
        firstTerm *= value.expected ** 2 + value.stdDev ** 2
        secondTerm *= value.expected ** 2
    }

    return {
        expected,
        stdDev: Math.sqrt(Math.max(0, firstTerm - secondTerm)),
    }
}

export function multiplyFixed(value, scalar) {
    return multiplyValues(value, fixedValue(scalar))
}

export function multiplyIndependent(value, scalar) {
    return {
        expected: value.expected * scalar,
        stdDev: Math.sqrt(Math.abs(scalar)) * value.stdDev,
    }
}

export function chanceMultiplierStdDev(chance, multiplier) {
    return {
        expected: chance * (multiplier - 1) + 1,
        stdDev: Math.sqrt(chance * (1 - chance)) * (multiplier - 1),
    }
}

export function applyStdDev(value, stdDeviations) {
    return value.expected + stdDeviations * value.stdDev
}

function standardTank(name, overrides = {}) {
    return {
        name,
        combatRole: 'Tank',
        mainStat: 'strength',
        secondaryStat: 'tenacity',
        autoAttackStat: 'strength',
        aaPotency: MELEE_AUTO_POTENCY,
        minLevel: 70,
        maxLevel: 100,
        ...overrides,
    }
}

function standardHealer(name, overrides = {}) {
    return {
        name,
        combatRole: 'Healer',
        mainStat: 'mind',
        secondaryStat: 'piety',
        autoAttackStat: 'strength',
        aaPotency: MELEE_AUTO_POTENCY,
        minLevel: 70,
        maxLevel: 100,
        traitDamageMultiplier: (attackType) => attackType === ATTACK_TYPES.AUTO_ATTACK ? 1 : 1.3,
        ...overrides,
    }
}

function standardMelee(name, mainStat, gearFamily, overrides = {}) {
    return {
        name,
        combatRole: 'Melee',
        mainStat,
        secondaryStat: 'dhit',
        autoAttackStat: mainStat,
        gearFamily,
        aaPotency: MELEE_AUTO_POTENCY,
        minLevel: 70,
        maxLevel: 100,
        ...overrides,
    }
}

function standardRanged(name, overrides = {}) {
    return {
        name,
        combatRole: 'Ranged',
        mainStat: 'dexterity',
        secondaryStat: 'dhit',
        autoAttackStat: 'dexterity',
        aaPotency: RANGED_AUTO_POTENCY,
        minLevel: 70,
        maxLevel: 100,
        traitDamageMultiplier: (attackType) => attackType === ATTACK_TYPES.AUTO_ATTACK ? 1 : 1.2,
        ...overrides,
    }
}

function standardCaster(name, overrides = {}) {
    return {
        name,
        combatRole: 'Caster',
        mainStat: 'intelligence',
        secondaryStat: 'dhit',
        autoAttackStat: 'strength',
        aaPotency: MELEE_AUTO_POTENCY,
        minLevel: 70,
        maxLevel: 100,
        traitDamageMultiplier: (attackType) => attackType === ATTACK_TYPES.AUTO_ATTACK ? 1 : 1.3,
        ...overrides,
    }
}

function normalizeJobData(jobData) {
    return Object.freeze({
        combatRole: jobData.combatRole ?? 'Melee',
        mainStat: jobData.mainStat ?? 'strength',
        secondaryStat: jobData.secondaryStat ?? 'dhit',
        autoAttackStat: jobData.autoAttackStat ?? jobData.mainStat ?? 'strength',
        aaPotency: jobData.aaPotency ?? MELEE_AUTO_POTENCY,
        minLevel: jobData.minLevel ?? 1,
        maxLevel: jobData.maxLevel ?? 100,
        ...jobData,
    })
}

function normalizeFinalBonuses(finalBonuses = {}) {
    return Object.freeze({
        critChance: numberOr(finalBonuses.critChance, 0),
        critDamage: numberOr(finalBonuses.critDamage, finalBonuses.critDmg, 0),
        directHitChance: numberOr(finalBonuses.directHitChance, finalBonuses.dhitChance, 0),
        directHitDamage: numberOr(finalBonuses.directHitDamage, finalBonuses.dhitDmg, 0),
        determinationMultiplier: numberOr(finalBonuses.determinationMultiplier, finalBonuses.detMulti, 0),
        forceCrit: Boolean(finalBonuses.forceCrit),
        forceDirectHit: Boolean(finalBonuses.forceDirectHit ?? finalBonuses.forceDh),
    })
}

function usesCasterDamageFormula(derivedStats, attackType) {
    return (derivedStats.jobData.combatRole === 'Caster' || derivedStats.jobData.combatRole === 'Healer')
        && attackType !== ATTACK_TYPES.AUTO_ATTACK
}

function isHasteAffectedAttackType(attackType) {
    return attackType === ATTACK_TYPES.WEAPONSKILL
        || attackType === ATTACK_TYPES.SPELL
        || attackType === ATTACK_TYPES.AUTO_ATTACK
}

function resolveJobStatMultiplier(jobStatMultipliers, stat) {
    return jobStatMultipliers?.[stat] ?? DEFAULT_JOB_STAT_MULTIPLIERS[stat] ?? 100
}

function numberOr(...values) {
    for (const value of values) {
        if (Number.isFinite(value)) {
            return value
        }
    }

    return 0
}

function clamp(min, max, value) {
    return Math.max(min, Math.min(max, value))
}

function assertPrecisionPlaces(places) {
    if (places % 1 !== 0 || places < 0) {
        throw new Error(`Invalid places input ${places}; expected a non-negative integer`)
    }
}

function deepFreeze(value) {
    for (const key of Object.keys(value)) {
        if (value[key] && typeof value[key] === 'object' && !Object.isFrozen(value[key])) {
            deepFreeze(value[key])
        }
    }

    return Object.freeze(value)
}
