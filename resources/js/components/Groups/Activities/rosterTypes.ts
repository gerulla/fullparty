export type LocalizedText = Record<string, string | null | undefined> | null | undefined;

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
	} | null
};

export type ActivitySlot = {
	id: number
	group_key: string
	group_label: LocalizedText
	slot_key: string
	slot_label: LocalizedText
	position_in_group: number
	sort_order: number
	is_bench: boolean
	assigned_character_id: number | null
	assignment_source: 'application' | 'manual' | null
	assignment_application_id: number | null
	can_return_to_queue: boolean
	attendance_status: 'assigned' | 'checked_in' | 'late' | null
	checked_in_at: string | null
	state_token: string
	assigned_character: {
		id: number
		name: string
		avatar_url: string | null
		world: string | null
		datacenter: string | null
	} | null
	field_values: ActivitySlotFieldValue[]
};
