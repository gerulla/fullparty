import type { InjectionKey } from 'vue'

export type GearsetDisplay = 'expanded' | 'compact'
export type GearNames = Record<'en' | 'de' | 'fr' | 'ja', string>
export type GearItem = { id: number; names: GearNames; icon: number | null; itemLevel: number }
export type GearSlot = 'Weapon' | 'OffHand' | 'Head' | 'Body' | 'Hand' | 'Legs' | 'Feet' | 'Ears' | 'Neck' | 'Wrist' | 'RingLeft' | 'RingRight'
export type EquippedGearItem = GearItem & { slot: GearSlot; materia: GearItem[]; relicStats: Record<string, number> }
export type GearsetSnapshot = {
    version: 1; sourceUrl: string; importedAt: string; name: string; description: string
    job: string; level: number; partyBonus: number; itemLevel: number; itemLevelSync: number | null
    gcd: number | null; stats: Record<string, number>; items: EquippedGearItem[]; food: GearItem | null
}
export type GearsetCandidate = { index: number; name: string; job: string }
export type GearsetImportResult = { sets: GearsetCandidate[]; snapshots: GearsetSnapshot[] }
export const gearsetImportKey: InjectionKey<{ groupSlug: () => string }> = Symbol('gearsetImport')
