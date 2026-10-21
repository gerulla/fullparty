import type { CharacterClaimError, CharacterClaimPreviewScreen, CharacterClaimPreviewState } from '@/Types/CharacterClaim'
import { nyssaPreviewCharacter } from '@/utils/characterPreview'

export const characterClaimPreviewScreens: CharacterClaimPreviewScreen[] = [
    'preparing', 'no_code', 'instructions', 'instructions_verified', 'copied', 'copy_error',
    'checking', 'complete', 'transfer', 'code_not_found', 'expired_code', 'code_changed', 'ownership_changed', 'lodestone_unavailable',
    'rate_limit', 'session', 'unavailable', 'generic', 'linked_unverified', 'linked_verified',
    'claimed_elsewhere',
]
const errors: CharacterClaimError[] = [
    'code_not_found', 'expired_code', 'code_changed', 'ownership_changed', 'lodestone_unavailable', 'rate_limit', 'session', 'unavailable', 'generic',
]

export function characterClaimPreview(screen: CharacterClaimPreviewScreen, expiresAt: string): CharacterClaimPreviewState {
    const conflict = screen === 'linked_unverified' || screen === 'linked_verified'
    const claimed = ['complete', 'transfer', 'claimed_elsewhere'].includes(screen)
    const hasCode = !['preparing', 'no_code', 'expired_code', 'code_changed', 'ownership_changed'].includes(screen) && !conflict && !claimed

    return {
        character: {
            ...nyssaPreviewCharacter, id: 0,
            status: claimed ? 'claimed' : ['instructions_verified', 'linked_verified'].includes(screen) ? 'verified' : 'unverified',
        },
        stage: conflict ? 'linked_elsewhere'
            : ['checking', 'complete', 'transfer', 'claimed_elsewhere'].includes(screen)
                ? screen as CharacterClaimPreviewState['stage'] : 'instructions',
        code: hasCode ? 'FP-PREVIEW-NYSSA-NIGHTCLAW' : null,
        expiresAt: hasCode ? expiresAt : null,
        busy: ['preparing', 'checking'].includes(screen),
        copied: screen === 'copied',
        copyError: screen === 'copy_error',
        errorKey: errors.find(error => error === screen) ?? null,
    }
}
