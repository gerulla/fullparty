import { localizedValue } from "@/utils/localizedValue"
import type { ActivityCompletionSummary, ActivityCompletionSummarySource } from "@/Types/ActivityProgression"

type TranslateFunction = (key: string, params?: Record<string, unknown>) => string

type BuildActivityCompletionSummaryOptions = {
	activity: ActivityCompletionSummarySource | null | undefined
	locale: string
	fallbackLocale: string
	t: TranslateFunction
}

export const buildActivityCompletionSummary = ({
	activity,
	locale,
	fallbackLocale,
	t,
}: BuildActivityCompletionSummaryOptions): ActivityCompletionSummary | null => {
	if (!activity || activity.status !== "complete") {
		return null
	}

	const furthestProgPoint = activity.prog_points.find((progPoint) => progPoint.key === activity.furthest_progress_key)
	const milestones = [...activity.progress_milestones]
		.sort((left, right) => left.sort_order - right.sort_order)
		.map((milestone) => ({
			key: milestone.milestone_key,
			label: localizedValue(milestone.milestone_label, locale, fallbackLocale) || milestone.milestone_key,
			kills: milestone.kills,
			bestProgressPercent: milestone.best_progress_percent,
		}))

	const hasMilestoneResult = milestones.some(milestone => milestone.kills > 0 || milestone.bestProgressPercent !== null)
	if (!hasMilestoneResult && !activity.completed_at && !activity.progress_entry_mode && !activity.progress_notes
		&& !activity.progress_link_url && !activity.furthest_progress_key && activity.furthest_progress_percent === null) {
		return null
	}

	return {
		completedAt: activity.completed_at,
		sourceLabel: activity.progress_entry_mode
			? t(`groups.activities.management.complete_activity_modal.methods.${activity.progress_entry_mode}`)
			: null,
		furthestPointLabel: furthestProgPoint
			? (localizedValue(furthestProgPoint.label, locale, fallbackLocale) || furthestProgPoint.key)
			: milestones.find(milestone => milestone.key === activity.furthest_progress_key)?.label ?? activity.furthest_progress_key,
		bestProgressPercent: activity.furthest_progress_percent,
		progressLinkUrl: activity.progress_link_url,
		notes: activity.progress_notes,
		milestones,
	}
}
