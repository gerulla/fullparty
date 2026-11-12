import type { RunParticipationStatus } from '@/Types/RunParticipation'
import { getRunStatusIcon } from '@/utils/runStatusIcons'

// Application decisions, placement and attendance share colors and icons across run surfaces.
export const RUN_PARTICIPATION_META = {
    pending: { color: 'warning', icon: getRunStatusIcon('pending'), participating: true },
    approved: { color: 'success', icon: getRunStatusIcon('approved'), participating: true },
    assigned: { color: 'brand', icon: getRunStatusIcon('assigned'), participating: true },
    on_bench: { color: 'info', icon: getRunStatusIcon('on_bench'), participating: true },
    filled_in: { color: 'info', icon: getRunStatusIcon('filled_in'), participating: true },
    checked_in: { color: 'success', icon: getRunStatusIcon('checked_in'), participating: true },
    late: { color: 'warning', icon: getRunStatusIcon('late'), participating: true },
    missing: { color: 'error', icon: getRunStatusIcon('missing'), participating: false },
    declined: { color: 'error', icon: getRunStatusIcon('declined'), participating: false },
    cancelled: { color: 'error', icon: getRunStatusIcon('cancelled'), participating: false },
    withdrawn: { color: 'neutral', icon: getRunStatusIcon('withdrawn'), participating: false },
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
