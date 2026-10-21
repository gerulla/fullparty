import type { CharacterTransferSummary } from './CharacterTransfer'
export type CharacterAddMethod = 'xivauth' | 'lodestone'
export type CharacterAddStep = 'method' | 'select' | 'loading' | 'transfer' | 'complete'
export type CharacterOnboardingPreviewScreen = 'live' | 'method' | 'list_loading' | 'list' | 'selected' | 'loading' | 'complete' | 'transfer' | 'transferred' | 'partial' | 'refresh_failed' | 'empty' | 'error' | 'reconnect'
export type CharacterImportStage = 'profile' | 'classes' | 'phantom_jobs' | 'achievements' | 'fflogs' | 'saving'
export type CharacterImportProgress = { stage: CharacterImportStage; percent: number }
export type CharacterImportResult = { character: AddedCharacter; partial: boolean }

export type CharacterCandidate = {
    lodestone_id: string
    name: string
    world: string
    datacenter: string | null
    avatar_url: string | null
}

export type AddedCharacter = CharacterCandidate & {
    id: number
    status: 'unverified' | 'verified' | 'claimed'
}

export type CharacterOnboardingResponse = {
    character: AddedCharacter
    outcome: 'added' | 'claim_required' | 'already_claimed'
    transferred: boolean
    transfer?: CharacterTransferSummary | null
}

export type CharacterCreateProps = {
    xivAuthLinked: boolean
    resumeXivAuth: boolean
    worldGroups: {
        datacenter: string | null
        worlds: { label: string; value: string }[]
    }[]
    errors?: Record<string, string>
}
