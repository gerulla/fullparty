export type RunDiscoveryDateRange = "upcoming" | "next_7_days" | "next_30_days" | "this_week" | "next_week" | "custom_range" | "custom_day"
export type RunDiscoveryTimeOfDay = "any" | "morning" | "afternoon" | "evening" | "night"
export type RunDiscoveryRoleCategory = "any" | "tank" | "healer" | "dps" | null
export type RunDiscoveryClassRoleGroup = "tank" | "healer" | "melee" | "phys" | "magic"
export type RunDiscoverySort = "starting_soonest" | "newest_posted" | "recently_updated" | "open_slots"

export type RunDiscoveryFilterState = {
	query: string
	saved_only: boolean
	activity_category: string
	activity_type: string
	prog_point: string
	region: string
	datacenter: string
	group: string
	timezone: string
	date_range: RunDiscoveryDateRange
	date_from: string | null
	date_to: string | null
	time_of_day: RunDiscoveryTimeOfDay
	run_style: string
	beginner_friendly: boolean
	language: string
	role_category: RunDiscoveryRoleCategory
	class_keys: string[]
	group_type: string
	application_status: string | null
	intensity: string | null
	voice_expectation: string | null
	sort?: RunDiscoverySort
	page?: number
}

export type RunDiscoveryLookupOption = {
	label: string
	value: string
}

export type RunDiscoveryDatacenterOption = RunDiscoveryLookupOption & {
	region: string | null
}

export type RunDiscoveryProgPointOption = RunDiscoveryLookupOption

export type RunDiscoveryActivityTypeOption = {
	value: string
	label: string
	small_image_url: string | null
	difficulty: string | null
	prog_points: RunDiscoveryProgPointOption[]
}

export type RunDiscoveryClassOption = {
	key: string
	label: string
	shorthand: string
	group: RunDiscoveryClassRoleGroup
	icon_url: string | null
}

export type RunDiscoveryLookups = {
	activity_types: RunDiscoveryActivityTypeOption[]
	class_options: RunDiscoveryClassOption[]
	regions: RunDiscoveryLookupOption[]
	datacenters: RunDiscoveryDatacenterOption[]
	groups: RunDiscoveryLookupOption[]
	languages: RunDiscoveryLookupOption[]
	run_styles: string[]
	intensities: string[]
	voice_expectations: string[]
}

export type RunDiscoveryPageProps = {
	lookups: RunDiscoveryLookups
}

export type RunDiscoveryResultRoleSlot = {
	key: "tank" | "healer" | "dps"
	count: number
}

export type RunDiscoveryResultItemData = {
	id: number
	image_url: string | null
	title: string
	activity_type_name: string
	difficulty: string | null
	target_prog_point_key: string | null
	target_prog_point_label: string | null
	group_name: string | null
	group_slug: string | null
	group_profile_picture_url: string | null
	group_discord_invite_url: string | null
	group_type: string | null
	organizer: {
		name: string | null
		avatar_url: string | null
		world?: string | null
		datacenter?: string | null
	} | null
	description: string | null
	min_item_level: number | null
	run_style: string | null
	intensity: string | null
	voice_expectation: string | null
	beginner_friendly: boolean
	allow_guest_applications: boolean
	starts_at: string | null
	datacenter: string | null
	world: string | null
	role_slots: RunDiscoveryResultRoleSlot[]
	filled_slots: number
	total_slots: number
	is_saved: boolean
	has_existing_application: boolean
	can_apply: boolean
	links: {
		view: string
		apply: string | null
	}
}

export type RunDiscoveryDiscoverResponse = {
	ids: number[]
	items: RunDiscoveryResultItemData[]
	meta: {
		current_page: number
		last_page: number
		per_page: number
		total: number
	}
}
