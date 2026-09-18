type MateriaStat = 'crit' | 'dhit' | 'determination' | 'skillspeed' | 'spellspeed' | 'piety' | 'tenacity'
type MateriaBonus = { stat: MateriaStat; value: number }

// Bundled lookup also supports snapshots saved before the compact meld display.
// XIVAPI Materia sheet (game data 541c0c12e07da325): Item[], BaseParam and Value.
// https://v2.xivapi.com/api/sheet/Materia?fields=BaseParam.Name,Item[].Name,Value&limit=100
const grades = [1, 2, 3, 4, 6, 16, 8, 24, 12, 36, 18, 54]
const families: Record<MateriaStat, number[]> = {
    piety: [5629, 5630, 5631, 5632, 5633, 18011, 25186, 26727, 33917, 33930, 41757, 41770],
    dhit: [5664, 5665, 5666, 5667, 5668, 18018, 25187, 26728, 33918, 33931, 41758, 41771],
    crit: [5669, 5670, 5671, 5672, 5673, 18019, 25188, 26729, 33919, 33932, 41759, 41772],
    determination: [5674, 5675, 5676, 5677, 5678, 18020, 25189, 26730, 33920, 33933, 41760, 41773],
    tenacity: [5679, 5680, 5681, 5682, 5683, 18021, 25190, 26731, 33921, 33934, 41761, 41774],
    skillspeed: [5714, 5715, 5716, 5717, 5718, 18028, 25197, 26738, 33928, 33941, 41768, 41781],
    spellspeed: [5719, 5720, 5721, 5722, 5723, 18029, 25198, 26739, 33929, 33942, 41769, 41782],
}
const bonuses = new Map<number, MateriaBonus>(Object.entries(families).flatMap(([stat, ids]) =>
    ids.map((id, grade) => [id, { stat: stat as MateriaStat, value: grades[grade] }] as const),
))

export function materiaBonus(id: number): MateriaBonus | null {
    return bonuses.get(id) ?? null
}
