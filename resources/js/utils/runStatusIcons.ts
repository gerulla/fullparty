import type { ActivityStatus } from '@/Types/ActivityCore'
import type { RunParticipationStatus } from '@/Types/RunParticipation'

// Edit a status icon here to update calendars, run cards, applications and notifications.
const RUN_STATUS_ICONS = {
    draft: 'i-lucide-clipboard-list',
    scheduled: 'i-lucide-calendar-check-2',
    upcoming: 'i-lucide-sparkles',
    ongoing: 'i-lucide-activity',
    complete: 'i-lucide-flag',
    cancelled: 'i-lucide-ban',
    pending: 'i-lucide-clock',
    approved: 'i-lucide-check',
    assigned: 'i-lucide-user-round-check',
    on_bench: 'i-lucide-armchair',
    filled_in: 'i-lucide-user-round-plus',
    checked_in: 'i-lucide-circle-check',
    late: 'i-lucide-clock-alert',
    missing: 'i-lucide-user-round-x',
    declined: 'i-lucide-circle-x',
    withdrawn: 'i-lucide-undo-2',
    unknown: 'i-lucide-calendar-range',
} as const satisfies Record<ActivityStatus | RunParticipationStatus | 'unknown', string>

export function getRunStatusIcon(status: string | null | undefined): string {
    return status && Object.hasOwn(RUN_STATUS_ICONS, status)
        ? RUN_STATUS_ICONS[status as keyof typeof RUN_STATUS_ICONS]
        : RUN_STATUS_ICONS.unknown
}
