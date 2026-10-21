import type { AddedCharacter, CharacterAddMethod, CharacterAddStep, CharacterOnboardingPreviewScreen } from '@/Types/CharacterOnboarding'
import { nyssaPreviewCharacter as nyssa } from '@/utils/characterPreview'

export function characterOnboardingPreview(screen: CharacterOnboardingPreviewScreen, method: CharacterAddMethod) {
    const complete = ['complete', 'transfer', 'transferred', 'partial', 'refresh_failed'].includes(screen)
    const hasSelection = ['selected', 'loading'].includes(screen) || complete
    const step: CharacterAddStep = screen === 'live' || screen === 'method' ? 'method'
        : screen === 'loading' ? 'loading'
        : screen === 'transfer' ? 'transfer' : complete ? 'complete'
        : 'select'

    return {
        step,
        candidates: ['list', 'selected'].includes(screen) ? [{ ...nyssa }] : [],
        selected: hasSelection ? { ...nyssa } : null,
        added: complete ? { ...nyssa, id: 0, status: method === 'xivauth' || ['transfer', 'transferred'].includes(screen) ? 'claimed' : 'unverified' } as AddedCharacter : null,
        transferred: ['transfer', 'transferred'].includes(screen),
        busy: screen === 'list_loading' || screen === 'loading',
        searched: ['list', 'selected', 'empty'].includes(screen),
        refreshFailed: screen === 'refresh_failed',
        partial: screen === 'partial',
        reconnect: screen === 'reconnect',
        errorKey: screen === 'error' ? 'generic' : screen === 'reconnect' ? 'xivauth_reconnect' : null,
    }
}
