import type { AttendeeActivity } from '@/Types/ActivityAttendee'
import type { ActivityCompletionSummarySource } from '@/Types/ActivityProgression'
import { parseTimestamp } from '@/utils/dateTimeFormat'

export function previewRunCompletion(activity: AttendeeActivity, notes: string): ActivityCompletionSummarySource {
    const encounters = activity.progress_milestones.length
        ? [...activity.progress_milestones].sort((a, b) => a.sort_order - b.sort_order)
        : activity.prog_points.map((point, index) => ({
            milestone_key: point.key, milestone_label: point.label, sort_order: index,
        }))
    const furthestIndex = Math.min(2, encounters.length - 1)
    const milestones = encounters.map((encounter, index) => {
        if (index < furthestIndex) {
            return { ...encounter, kills: index === 0 ? 2 : 1, best_progress_percent: 100 }
        }
        if (index === furthestIndex) {
            return { ...encounter, kills: 0, best_progress_percent: 76 }
        }
        return { ...encounter, kills: 0, best_progress_percent: index === furthestIndex + 1 ? 0 : null }
    })
    const furthest = milestones[furthestIndex]
    const start = parseTimestamp(activity.starts_at)
    const finishedAt = start && new Date(start.getTime() + (activity.duration_hours ?? 2) * 3_600_000)

    return {
        status: 'complete',
        completed_at: finishedAt?.toISOString() ?? null,
        progress_entry_mode: milestones.length ? 'manual' : null,
        progress_link_url: null,
        progress_notes: notes,
        furthest_progress_key: furthest?.milestone_key ?? null,
        furthest_progress_percent: furthest?.best_progress_percent ?? null,
        prog_points: activity.prog_points,
        progress_milestones: milestones,
    }
}
