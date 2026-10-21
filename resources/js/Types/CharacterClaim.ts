import type { AddedCharacter } from './CharacterOnboarding'
import type { CharacterTransferSummary } from './CharacterTransfer'

export type CharacterClaimContext = 'owned' | 'transfer' | 'already_claimed'
export type CharacterClaimProps = { character: AddedCharacter; context?: CharacterClaimContext; transfer?: CharacterTransferSummary | null }
export type CharacterClaimStage = 'instructions' | 'checking' | 'complete' | 'transfer' | 'claimed_elsewhere'
export type CharacterClaimError = 'code_not_found' | 'expired_code' | 'code_changed' | 'ownership_changed' | 'lodestone_unavailable' | 'rate_limit' | 'session' | 'unavailable' | 'generic'
export type CharacterClaimConflictStage = 'linked_elsewhere' | 'claimed_elsewhere'
export type CharacterClaimPreviewScreen = 'live' | 'preparing' | 'no_code' | 'instructions' | 'instructions_verified'
    | 'copied' | 'copy_error' | 'checking' | 'complete' | 'transfer' | CharacterClaimError
    | 'linked_unverified' | 'linked_verified' | 'claimed_elsewhere'
export type CharacterClaimPreviewState = {
    character: AddedCharacter
    stage: CharacterClaimStage | CharacterClaimConflictStage
    code: string | null
    expiresAt: string | null
    busy: boolean
    copied: boolean
    copyError: boolean
    errorKey: CharacterClaimError | null
}
export type CharacterClaimCodeResponse = {
    character: AddedCharacter
    code: string | null
    expires_at: string | null
    context: CharacterClaimContext
    transfer?: CharacterTransferSummary | null
}
