import type { CharacterIdentity } from '../Types/Characters'

export function characterVerificationStatus(character: Pick<CharacterIdentity, 'claimed_at' | 'verified_at'>) {
    if (character.claimed_at) return { key: 'claimed', color: 'brand', icon: 'i-lucide-shield-check' } as const
    if (character.verified_at) return { key: 'verified', color: 'success', icon: 'i-lucide-check' } as const
    return { key: 'unverified', color: 'error', icon: 'i-lucide-circle-help' } as const
}
