import type { LocalizedText } from "@/Types/Common"
import type { ActivityProgressPoint } from "@/Types/ActivityCore"
import type { QueueFilterField } from "@/Types/ActivityQueue"
import type { ActivityCompositionClassOption, ActivityMissingAssignment, ActivityRosterSummaryPreset, ActivitySlot, ActivitySlotCompositionHint } from "@/Types/ActivityRoster"

export type ActivityProgressMilestone = {
	id: number
	milestone_key: string
	milestone_label: LocalizedText
	kills: number
	best_progress_percent: number | null
}

export type ActivityManagementProgressMilestone = ActivityProgressMilestone & {
	sort_order: number
	source: string | null
	notes: string | null
}

export type ActivityPartyFinderInfo = {
	character_name: string
	world: string
	password: string
	published_at: string | null
}

export type ActivityCompletionPreviewMilestone = {
	milestone_key: string
	kills: number
	best_progress_percent: number | null
}

export type FflogsEncounterProgress = {
	name: string
	kills: number
	progress: number
}

export type FflogsProgressResponse = {
	title: string
	zone_id: number
	encounters: FflogsEncounterProgress[]
	encounter_count: number
	total_kills: number
} | null

export type ActivityDetails = {
	id: number
	activity_type: {
		id: number | null
		slug: string | null
		draft_name: LocalizedText
	}
	activity_type_version_id: number
	fflogs_zone_id: number | null
	difficulty: string | null
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
	target_prog_point_key: string | null
	furthest_progress_key: string | null
	furthest_progress_percent: number | null
	is_public: boolean
	needs_application: boolean
	secret_key: string | null
	progress_entry_mode: string | null
	progress_link_url: string | null
	progress_notes: string | null
	completed_at: string | null
	party_finder_info: ActivityPartyFinderInfo | null
	organized_by: {
		id: number
		name: string
		avatar_url: string | null
	} | null
	organized_by_character: {
		id: number
		user_id: number
		name: string
		avatar_url: string | null
	} | null
	slot_count: number
	bench_slot_count: number
	application_count: number
	pending_application_count: number
	progress_milestone_count: number
	can_use_fflogs_completion: boolean
	prog_points: ActivityProgressPoint[]
	roster_summary_presets: ActivityRosterSummaryPreset[]
	slot_field_definitions: QueueFilterField[]
	composition_class_options: ActivityCompositionClassOption[]
	slots: ActivitySlot[]
	missing_assignments: ActivityMissingAssignment[]
	progress_milestones: ActivityManagementProgressMilestone[]
}

export type ActivityManagementPatch = {
	type?: "reload"
	reason?: string
	updated_slots?: ActivitySlot[]
	updated_slot_composition_hints?: Array<{
		slot_id: number
		composition_hints: ActivitySlotCompositionHint[]
	}>
	pending_application_count?: number
	queue_invalidate?: boolean
	queue_change_reason?: "application_created" | "application_updated" | "application_withdrawn" | string
	queue_new_application_count?: number
	queue_new_application_ids?: number[]
	queue_updated_application_names?: string[]
	queue_withdrawn_application_names?: string[]
	queue_application_sync_ids?: number[]
	queue_application_remove_ids?: number[]
	upsert_missing_assignments?: ActivityDetails["missing_assignments"]
	remove_missing_assignment_ids?: number[]
}

export type SlotDesignation = "host" | "raid_leader"
