export type LocalizedText = Record<string, string | null | undefined> | null | undefined;

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

export type QueueApplication = {
	id: number
	user: {
		id: number
		name: string
		avatar_url: string | null
		notes: {
			can_view: boolean
			can_add: boolean
			current_group_count: number
			shared_count: number
			current_group: Array<{
				id: number
				severity: 'info' | 'warning' | 'critical'
				body: string
				is_shared_with_groups: boolean
				created_at: string | null
				permissions: {
					can_edit_body: boolean
					can_delete: boolean
					can_add_addendum: boolean
				}
				author: {
					id: number
					name: string
					avatar_url: string | null
				} | null
				addenda: Array<{
					id: number
					body: string
					created_at: string | null
					author: {
						id: number
						name: string
						avatar_url: string | null
					} | null
				}>
				source_group: {
					id: number | null
					name: string | null
					slug: string | null
				} | null
			}>
			shared: Array<{
				id: number
				severity: 'info' | 'warning' | 'critical'
				body: string
				is_shared_with_groups: boolean
				created_at: string | null
				permissions: {
					can_edit_body: boolean
					can_delete: boolean
					can_add_addendum: boolean
				}
				author: {
					id: number
					name: string
					avatar_url: string | null
				} | null
				addenda: Array<{
					id: number
					body: string
					created_at: string | null
					author: {
						id: number
						name: string
						avatar_url: string | null
					} | null
				}>
				source_group: {
					id: number | null
					name: string | null
					slug: string | null
				} | null
			}>
		}
	} | null
	selected_character: {
		id: number
		name: string
		avatar_url: string | null
		world: string | null
		datacenter: string | null
		occult_level: number | null
		phantom_mastery: number | null
	} | null
	status: string
	notes: string | null
	submitted_at: string | null
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
}

export type QueueFilterMilestone = {
	key: string
	label: LocalizedText
	matcher_type: string
	encounter_id: number | null
	phase_id: number | null
}
