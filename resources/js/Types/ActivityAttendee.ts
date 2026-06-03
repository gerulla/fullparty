import type { ActivityRosterSummaryPreset, ActivitySlot } from "@/Types/ActivityRoster"
import type { LocalizedText } from "@/Types/Common"
import type { ActivityProgressPoint } from "@/Types/ActivityCore"
import type { ActivityProgressionMilestoneRecord } from "@/Types/ActivityProgression"

export type PublicGroupSummary = {
	id: number
	name: string
	slug: string
	is_visible: boolean
}

export type AttendeeActivityType = {
	id: number | null
	slug: string | null
	draft_name: LocalizedText
}

export type AttendeeOrganizer = {
	id: number
	name: string
	avatar_url: string | null
}

export type AttendeeOrganizerCharacter = {
	id: number
	user_id: number
	name: string
	avatar_url: string | null
}

export type AttendeeActivity = {
	id: number
	activity_type: AttendeeActivityType
	activity_type_version_id: number
	title: string | null
	description: string | null
	small_image_url: string | null
	banner_image_url: string | null
	notes: string | null
	status: string
	cancellation_reason: string | null
	starts_at: string | null
	duration_hours: number | null
	datacenter: string | null
	intensity: string | null
	min_item_level: number | null
	beginner_friendly: boolean
	run_style: string | null
	difficulty: string | null
	target_prog_point_key: string | null
	target_prog_point_label: LocalizedText | null
	furthest_progress_key: string | null
	furthest_progress_percent: number | null
	needs_application: boolean
	allow_guest_applications: boolean
	progress_entry_mode: string | null
	progress_link_url: string | null
	progress_notes: string | null
	completed_at: string | null
	slot_count: number
	assigned_slot_count: number
	pending_application_count: number
	organized_by: AttendeeOrganizer | null
	organized_by_character: AttendeeOrganizerCharacter | null
	prog_points: ActivityProgressPoint[]
	roster_summary_presets: ActivityRosterSummaryPreset[]
	progress_milestones: ActivityProgressionMilestoneRecord[]
	slots: ActivitySlot[]
}

export type ActivityOverviewPermissions = {
	can_apply: boolean
	can_apply_as_guest: boolean
	can_manage: boolean
	can_self_assign: boolean
}
