import type { AllianceProgressStatus } from '@/Types/AllianceProgress'

export const allianceProgressTones: Record<AllianceProgressStatus, { slot: string; swatch: string }> = {
    cleared: { slot: 'border-yellow-500/80 bg-yellow-500/15', swatch: 'bg-yellow-500' },
    at_target: { slot: 'border-green-500/80 bg-green-500/15', swatch: 'bg-green-500' },
    below_target: { slot: 'border-orange-500/80 bg-orange-500/15', swatch: 'bg-orange-500' },
    no_progress: { slot: 'border-neutral-400/70 bg-neutral-400/10', swatch: 'bg-neutral-400' },
}
