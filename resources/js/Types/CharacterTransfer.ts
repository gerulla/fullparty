import type { LocalizedText } from './Common'

export type TransferChoice = 'keep' | 'discard'
export type TransferCategory = 'history' | 'upcoming'
export type CharacterTransferRun = {
    id: number
    category: TransferCategory
    title: string | null
    activity_type: LocalizedText
    group: string
    starts_at: string | null
    url: string | null
}
export type CharacterTransferSummary = {
    id: number
    pending: boolean
    method?: 'xivauth' | 'lodestone'
    history_choice: TransferChoice | null
    upcoming_choice: TransferChoice | null
    runs: CharacterTransferRun[]
}
