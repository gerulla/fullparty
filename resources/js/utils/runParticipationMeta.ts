import type { RunParticipationStatus } from '@/Types/RunParticipation'

// Application decisions, placement and attendance share these colors across all run surfaces.
export const RUN_PARTICIPATION_META = {
    pending: { color: 'warning', icon: 'i-lucide-clock', participating: true },
    approved: { color: 'success', icon: 'i-lucide-check', participating: true },
    assigned: { color: 'brand', icon: 'i-lucide-user-round-check', participating: true },
    on_bench: { color: 'info', icon: 'i-lucide-armchair', participating: true },
    filled_in: { color: 'info', icon: 'i-lucide-user-round-plus', participating: true },
    checked_in: { color: 'success', icon: 'i-lucide-circle-check', participating: true },
    late: { color: 'warning', icon: 'i-lucide-clock-alert', participating: true },
    missing: { color: 'error', icon: 'i-lucide-user-round-x', participating: false },
    declined: { color: 'error', icon: 'i-lucide-circle-x', participating: false },
    cancelled: { color: 'error', icon: 'i-lucide-ban', participating: false },
    withdrawn: { color: 'neutral', icon: 'i-lucide-undo-2', participating: false },
} as const satisfies Record<RunParticipationStatus, { color: string; icon: string; participating: boolean }>

export function getRunParticipationMeta(status: string | null | undefined) {
    return status && Object.hasOwn(RUN_PARTICIPATION_META, status)
        ? RUN_PARTICIPATION_META[status as RunParticipationStatus]
        : null
}

export const getRunParticipationColor = (status: string | null | undefined) => (
    getRunParticipationMeta(status)?.color ?? 'neutral'
)

export const isActiveRunParticipation = (status: string | null | undefined) => (
    getRunParticipationMeta(status)?.participating ?? false
)
