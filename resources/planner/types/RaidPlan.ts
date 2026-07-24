export type RaidPlanMode = 'view' | 'edit'

export type RaidPlanVisibility = 'unlisted' | 'public'

export interface RaidPlanAuthor {
	id: number
	name: string
	avatar_url: string | null
}

export interface RaidPlanLinks {
	view: string
	edit?: string
}

export interface RaidPlanPayload {
	id: number
	name: string
	description: string | null
	fight_id: number | null
	visibility: RaidPlanVisibility
	author: RaidPlanAuthor | null
	is_saved_to_account: boolean
	is_owned_by_current_user: boolean
	can_edit: boolean
	links: RaidPlanLinks
	created_at: string | null
	updated_at: string | null
}

export interface RaidPlanFightOption {
	id: number
	slug: string
	label: string
	difficulty: string | null
	image_url: string | null
}
