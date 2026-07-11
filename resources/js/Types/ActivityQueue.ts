import type { LocalizedText } from "@/Types/Common"
import type { MemberNoteSummary } from "@/Types/Groups"

export type QueueApplicationAnswerDisplayItem = {
	label: string
	role?: string | null
	icon_url?: string | null
	flat_icon_url?: string | null
	transparent_icon_url?: string | null
}

export type QueueApplicationAnswer = {
	question_key: string
	question_label: LocalizedText
	question_type: string
	source: string | null
	raw_value: unknown
	display_values: string[]
	role_values: string[]
	display_items: QueueApplicationAnswerDisplayItem[]
}

export type QueueApplicationUserStatItem = {
	label: string
	count: number
	role?: string | null
	icon_url?: string | null
	flat_icon_url?: string | null
	transparent_icon_url?: string | null
}

export type QueueApplicationUserStats = {
	group_run_count: number
	overall_run_count: number
	class: {
		group: QueueApplicationUserStatItem[]
		overall: QueueApplicationUserStatItem[]
	}
	phantom_job: {
		group: QueueApplicationUserStatItem[]
		overall: QueueApplicationUserStatItem[]
	}
}

export type QueueApplication = {
	id: number
	is_guest: boolean
	user: {
		id: number
		name: string
		avatar_url: string | null
		note_summary: MemberNoteSummary
	} | null
	applicant_character: {
		lodestone_id: string
		name: string
		avatar_url: string | null
		world: string | null
		datacenter: string | null
		is_claimed: boolean
	} | null
	selected_character: {
		id: number
		name: string
		avatar_url: string | null
		world: string | null
		datacenter: string | null
		lodestone_refreshed_at: string | null
		lodestone_last_checked_at: string | null
		occult_level: number | null
		phantom_mastery: number | null
		preferred_character_class_ids: string[]
		preferred_phantom_job_ids: string[]
		available_character_classes: Array<{
			id: string
			level: number
		}>
		available_phantom_jobs: Array<{
			id: string
			current_level: number
			max_level: number
			is_maxed: boolean
		}>
	} | null
	status: string
	notes: string | null
	submitted_at: string | null
	edited_at: string | null
	reviewed_at: string | null
	review_reason: string | null
	user_stats: QueueApplicationUserStats | null
	progress_milestones: Array<{
		key: string
		label: LocalizedText
		reached: boolean
		source: string
		kills: number
		progress_percent: number
	}>
	answers: QueueApplicationAnswer[]
}

export type QueueFilterField = {
	key: string
	application_key: string
	label: LocalizedText
	type: string
	source: string | null
	options: Array<{
		key: string
		label: LocalizedText
		meta?: {
			icon_url?: string | null
			flaticon_url?: string | null
			transparent_icon_url?: string | null
			role?: string | null
			shorthand?: string | null
		} | null
	}>
	filter_options?: Array<{
		key: string
		label: LocalizedText
		meta?: {
			icon_url?: string | null
			flaticon_url?: string | null
			transparent_icon_url?: string | null
			role?: string | null
			shorthand?: string | null
		} | null
	}>
}

export type QueueFilterMilestone = {
	key: string
	label: LocalizedText
	matcher_type: string
	encounter_id: number | null
	phase_id: number | null
}

export type ManualAssignmentCharacter = {
	id: number
	name: string
	avatar_url: string | null
	world: string | null
	datacenter: string | null
	user: {
		id: number
		name: string
		avatar_url: string | null
	} | null
	character_class_ids: string[]
	phantom_job_ids: string[]
}
