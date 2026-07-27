export type RaidPlanMode = 'view' | 'edit'
export type PlannerEditorTool =
	| 'select'
	| 'boss'
	| 'player'
	| 'markers'
	| 'text'

export type RaidPlanVisibility = 'unlisted' | 'public'

export interface RaidPlanAuthor {
	id: number
	name: string
	avatar_url: string | null
}

export interface RaidPlanLinks {
	view: string
	edit?: string
	asset_upload?: string
}

export type RaidPlanMechanicType = 'fixed' | 'random_set'

export type RaidPlanComponentType = 'arena_map' | 'marker_layout'

export type RaidPlanArenaMapDisplayMode = 'fit' | 'fill' | 'crop'
export type RaidPlanBossHitboxStyle = 'positionals' | 'no_positionals'
export type RaidPlanMarkerKey = '1' | '2' | '3' | '4' | 'A' | 'B' | 'C' | 'D'
export type RaidPlanMarkerLayoutType =
	| 'standard'
	| 'standard_flipped'
	| 'diamond'
	| 'square'
	| 'waymark_studio'

export interface RaidPlanElementComponent {
	id: string
	type: string
	offset_x: number
	offset_y: number
	rotation: number
}

export interface RaidPlanArenaMapComponent {
	id: string
	type: 'arena_map'
	image_url: string | null
	display_mode: RaidPlanArenaMapDisplayMode
	offset_x: number
	offset_y: number
	rotation: number
	crop_left: number
	crop_right: number
	crop_top: number
	crop_bottom: number
}

export interface RaidPlanBossElementComponent extends RaidPlanElementComponent {
	type: 'boss'
	scale: number
	color: string
	hitbox_style: RaidPlanBossHitboxStyle
}

export interface RaidPlanMarkerElementComponent extends RaidPlanElementComponent {
	type: 'marker'
	marker_key: RaidPlanMarkerKey
	scale: number
}

export interface RaidPlanMarkerLayoutComponent extends RaidPlanElementComponent {
	type: 'marker_layout'
	layout: RaidPlanMarkerLayoutType
	distance: number
	waymark_preset: string | null
}

export interface RaidPlanGenericComponent {
	id: string
	type: string
	[key: string]: unknown
}

export type RaidPlanSceneComponent =
	| RaidPlanArenaMapComponent
	| RaidPlanBossElementComponent
	| RaidPlanMarkerElementComponent
	| RaidPlanMarkerLayoutComponent
	| RaidPlanGenericComponent

export interface RaidPlanMechanicTimeline {
	components: RaidPlanSceneComponent[]
	[key: string]: unknown
}

export interface RaidPlanMechanicPayload {
	id: number
	name: string
	type: RaidPlanMechanicType
	sort_order: number
	duration_ms: number
	selection_weight: number
	is_enabled: boolean
	timeline: Record<string, unknown> | unknown[]
	timeline_schema_version: number
	variants: RaidPlanMechanicPayload[]
}

export interface RaidPlanMechanicDraft {
	key: string
	id: number | null
	name: string
	type: RaidPlanMechanicType
	duration_ms: number
	selection_weight: number
	is_enabled: boolean
	timeline: RaidPlanMechanicTimeline
	timeline_schema_version: number
	variants: RaidPlanMechanicDraft[]
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
	mechanics: RaidPlanMechanicPayload[]
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
