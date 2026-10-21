import type { CharacterTransferSummary } from '@/Types/CharacterTransfer'

export function characterTransferPreview(): CharacterTransferSummary {
    return {
        id: 0, pending: true, history_choice: null, upcoming_choice: null,
        runs: [
            { id: 1, category: 'history', title: 'Baldesion Arsenal — Newcomer Run', activity_type: { en: 'The Baldesion Arsenal' }, group: 'Forked Tower Enjoyers', starts_at: '2026-10-17T18:00:00Z', url: null },
            { id: 2, category: 'history', title: 'Blood Tower Clear', activity_type: { en: 'Forked Tower: Blood' }, group: 'Light Adventurers', starts_at: '2026-10-18T19:00:00Z', url: null },
            { id: 3, category: 'upcoming', title: 'Delubrum Reginae Savage', activity_type: { en: 'Delubrum Reginae (Savage)' }, group: 'Forked Tower Enjoyers', starts_at: '2026-10-25T18:00:00Z', url: null },
        ],
    }
}
