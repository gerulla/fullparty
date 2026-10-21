import type { CharacterTowerProgress } from '../Types/Characters'

export function characterTowerSummary(progress: CharacterTowerProgress | null | undefined) {
    const bosses = progress?.bosses ?? []

    return {
        cleared: (progress?.clears ?? 0) > 0,
        // Count bosses defeated, not repeated kills or rounded damage percentages.
        defeated: new Set(bosses.filter(boss => boss.kills > 0).map(boss => boss.key)).size,
        total: new Set(bosses.map(boss => boss.key)).size || 4,
    }
}
