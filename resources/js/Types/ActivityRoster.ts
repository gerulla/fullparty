import type { LocalizedText } from "@/Types/Common"

export type ActivitySlotFieldValue = {
	id: number
	field_key: string
	field_label: LocalizedText
	field_type: string
	source: string | null
	value: unknown
	display_value: LocalizedText | string | null
	display_meta: {
		name?: string | null
		shorthand?: string | null
		role?: string | null
		icon_url?: string | null
		flaticon_url?: string | null
		black_icon_url?: string | null
		transparent_icon_url?: string | null
		sprite_url?: string | null
		key?: string | null
		label?: LocalizedText | string | null
		prepop_id?: number | null
		refill_id?: number | null
		prepop_label?: string | null
		refill_label?: string | null
	} | null
}

export type ActivityApplicationFieldGroup = {
	question_key: string
	question_label: LocalizedText
	source: string | null
	items: Array<{
		label: string
		role?: string | null
		icon_url?: string | null
		flat_icon_url?: string | null
		transparent_icon_url?: string | null
	}>
}

export type ActivitySlotApplicationMatch = {
	key: string
	label: LocalizedText
	abbreviation: string
	matches: boolean
}

export type ActivitySlotCompositionHint = {
	id: number
	type: "role" | "class"
	key: string
	role_key: "tank" | "healer" | "dps" | null
	character_class_id: number | null
	sort_order: number
	character_class: {
		id: number
		name: string
		shorthand: string
		role: string
		icon_url: string | null
		flaticon_url: string | null
	} | null
}

export type ActivitySlotCompositionHintInput = {
	type: "role" | "class"
	key: string
}

export type ActivityCompositionClassOption = {
	id: number
	name: string
	shorthand: string
	role: string
	icon_url: string | null
	flaticon_url: string | null
}

export type ActivityFillInPartyOption = {
	key: string
	label: LocalizedText
}

export type ActivitySlot = {
	id: number
	slot_kind: "roster" | "bench" | "fill_in"
	group_key: string
	group_label: LocalizedText
	filled_group_key: string | null
	filled_group_label: LocalizedText | null
	slot_key: string
	slot_label: LocalizedText
	position_in_group: number
	sort_order: number
	is_bench: boolean
	is_fill_in: boolean
	is_host: boolean
	is_raid_leader: boolean
	assigned_character_id: number | null
	application_review_required: boolean
	application_review_required_at: string | null
	application_review_required_application_id: number | null
	assignment_source: "application" | "manual" | null
	assignment_application_id: number | null
	can_return_to_queue: boolean
	attendance_status: "assigned" | "checked_in" | "late" | null
	checked_in_at: string | null
	state_token: string
	composition_hints: ActivitySlotCompositionHint[]
	assigned_character: {
		id: number
		user_id: number | null
		name: string
		avatar_url: string | null
		world: string | null
		datacenter: string | null
	} | null
	application_field_groups: ActivityApplicationFieldGroup[]
	application_matches: ActivitySlotApplicationMatch[]
	field_values: ActivitySlotFieldValue[]
}

export type ActivityRosterSummaryRequirement = {
	source: string
	source_id: number
	comparison: "at_least" | "exactly" | "at_most"
	target_count: number
	scope_type: "all_slots" | "slot_group" | "slot_group_set"
	scope_group_keys: string[]
	scope_groups: Array<{
		key: string
		label: LocalizedText
	}>
	item: {
		id: number
		label: LocalizedText
		meta: {
			role?: string | null
			shorthand?: string | null
			icon_url?: string | null
			flaticon_url?: string | null
			black_icon_url?: string | null
			transparent_icon_url?: string | null
			sprite_url?: string | null
		} | null
	}
}

export type ActivityRosterSummaryPreset = {
	key: string
	label: LocalizedText
	description: LocalizedText
	requirements: ActivityRosterSummaryRequirement[]
}

export type ActivityRosterSummaryRequirementRow = {
	key: string
	scopeKey: string
	itemLabel: string
	itemIconUrl: string | null
	currentCount: number
	targetCount: number
	comparisonLabel: string
	comparisonShortLabel: string
	scopeLabel: string
	state: {
		color: "success" | "error" | "warning"
		toneClass: string
		badgeVariant: "soft"
	}
}

export type ActivityMissingAssignment = {
	id: number
	slot_id: number | null
	character: {
		id: number
		name: string
		avatar_url: string | null
		world: string | null
		datacenter: string | null
	} | null
	slot_label: LocalizedText
	group_label: LocalizedText
	marked_missing_at: string | null
}
