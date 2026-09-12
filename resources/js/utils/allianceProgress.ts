import type { AllianceProgressStatus } from '@/Types/AllianceProgress'

export const allianceProgressTones: Record<AllianceProgressStatus, { slot: string; swatch: string }> = {
    cleared: { slot: 'border-sky-400/80 bg-sky-400/15', swatch: 'bg-sky-400' },
    at_target: { slot: 'border-green-500/80 bg-green-500/15', swatch: 'bg-green-500' },
    below_target: { slot: 'border-orange-500/80 bg-orange-500/15', swatch: 'bg-orange-500' },
    no_progress: { slot: 'border-neutral-400/70 bg-neutral-400/10', swatch: 'bg-neutral-400' },
}
