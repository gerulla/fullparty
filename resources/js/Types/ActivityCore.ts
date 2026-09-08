import type { LocalizedText } from "@/Types/Common"

export type GroupQuickCreateShortcut = {
	id: number | null
	time: string
	time_mode: "server" | "local"
	sort_order: number
}
export type ActivityStatus = "draft" | "scheduled" | "assigned" | "upcoming" | "ongoing" | "complete" | "cancelled"

export type ActivityIntensity = "casual" | "midcore" | "hardcore"

export type ActivityRunStyle = "progression" | "clear" | "reclear" | "farm" | "marathon" | "speedrun" | "practice" | "blind"

export type ActivityDifficulty = "normal" | "extreme" | "unreal" | "exploration" | "savage" | "ultimate" | "chaotic" | "criterion"

export type ActivityStatusMeta = {
	color: string
	icon: string
	borderClass: string
	dotClass: string
}

export type ActivityProgressPoint = {
	key: string
	label: LocalizedText
}

export interface ActivityIndexItem {
	id: number
	group?: {
		id: number
		name: string
		slug: string
		profile_picture_url: string | null
		discord_invite_url: string | null
		group_type: string | null
		voice_expectation: string | null
		can_manage_activities: boolean
	}
	activity_type: {
		id: number | null
		slug: string | null
		draft_name: LocalizedText
	}
	activity_type_version_id: number
	title: string | null
	status: string
	small_image_url: string | null
	banner_image_url: string | null
	starts_at: string | null
	duration_hours: number | null
	target_prog_point_key: string | null
	target_prog_point_label: LocalizedText | null
	notes: string | null
	allow_guest_applications: boolean
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
		world: string | null
		datacenter?: string | null
	} | null
	datacenter: string | null
	intensity: ActivityIntensity | string | null
	min_item_level: number | null
	beginner_friendly: boolean
	run_style: ActivityRunStyle | string | null
	slot_count: number
	assigned_slot_count: number
	application_count: number
	has_existing_application: boolean
	links: {
		view: string
		apply: string | null
	}
	is_public: boolean
	needs_application?: boolean
	secret_key: string | null
	progress_milestone_count: number
	created_at: string | null
	updated_at: string | null
}

export type ActivityTypeOption = {
	id: number
	slug: string
	draft_name: LocalizedText
	current_published_version_id: number | null
	small_image_url: string | null
	banner_image_url: string | null
	difficulty: ActivityDifficulty | string | null
	default_min_item_level: number | null
	slot_count: number
	prog_points: ActivityProgressPoint[]
}

export type ActivityMetadataOptions = {
	intensities: string[]
	runStyles: string[]
}

export type OrganizerCharacterOption = {
	id: number
	user_id: number
	name: string | null
	user_name: string | null
	avatar_url: string | null
	world: string | null
}

export type ActivityFormShape = {
	activity_type_id: number | null
	organized_by_user_id: number | null
	organized_by_character_id: number | null
	status: string
	title: string
	notes: string
	starts_at: string | null
	duration_hours: number
	datacenter: string | null
	intensity: string
	min_item_level: number | null
	beginner_friendly: boolean
	run_style: string
	target_prog_point_key: string | null
}

export type ActivityFormOptions = {
	mode: "create" | "edit"
}

export type AccountApplication = {
	id: number
	status: string
	submitted_at: string | null
	reviewed_at: string | null
	review_reason: string | null
	notes: string | null
	can_edit: boolean
	can_withdraw: boolean
	is_rostered: boolean
	group: {
		name: string | null
		slug: string | null
		calendar_sync_enabled: boolean
	}
	activity: {
		id: number | null
		title: string | null
		description: string | null
		status: string | null
		starts_at: string | null
		duration_hours: number | null
		is_public: boolean
		secret_key: string | null
		type_name: LocalizedText
		target_prog_point_key: string | null
		target_prog_point_label: LocalizedText | null
		party_finder_info: {
			character_name: string
			world: string
			password: string
			published_at: string | null
		} | null
	}
	character: {
		name: string | null
		world: string | null
		datacenter: string | null
		avatar_url: string | null
	}
	assignment: {
		group_key: string | null
		group_label: LocalizedText | null
		slot_key: string | null
		slot_label: LocalizedText | null
		character_class: {
			id: number | null
			name: string | null
			shorthand: string | null
			role: string | null
		} | null
		phantom_job: {
			id: number | null
			name: string | null
		} | null
		raid_position: {
			key: string | null
			label: LocalizedText | string | null
		} | null
		attendance_status: string | null
		assigned_at: string | null
	} | null
}

export type ActivityCalendarDay = {
	key: string
	date: Date
	isCurrentMonth: boolean
	isToday: boolean
	activities: ActivityIndexItem[]
}
