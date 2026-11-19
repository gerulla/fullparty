import type { ActivityProgressPoint } from "@/Types/ActivityCore"
import type { LocalizedText } from "@/Types/Common"

export type ActivityProgressionMilestoneRecord = {
	milestone_key: string
	milestone_label: LocalizedText
	kills: number
	best_progress_percent: number | null
	sort_order: number
}

export type ActivityCompletionSummaryMilestone = {
	key: string
	label: string
	kills: number
	bestProgressPercent: number | null
}

export type ActivityCompletionSummary = {
	completedAt: string | null
	sourceLabel: string | null
	furthestPointLabel: string | null
	bestProgressPercent: number | null
	progressLinkUrl: string | null
	notes: string | null
	milestones: ActivityCompletionSummaryMilestone[]
}

export type ActivityCompletionSummarySource = {
	status: string
	completed_at: string | null
	progress_entry_mode: string | null
	progress_link_url: string | null
	progress_notes: string | null
	furthest_progress_key: string | null
	furthest_progress_percent: number | null
	prog_points: ActivityProgressPoint[]
	progress_milestones: ActivityProgressionMilestoneRecord[]
}
