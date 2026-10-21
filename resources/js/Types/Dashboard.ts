import type { LocalizedText } from "@/Types/Common"
import type { NotificationRecord } from "@/Types/Notifications"

export type DashboardCharacterClassOption = {
	id: number
	name: string
	shorthand: string
	role: string
	icon_url: string | null
	flaticon_url: string | null
}

export type DashboardHomeProfile = {
	display_character_class_id: number | null
	description: string | null
	background_image_url: string | null
	display_job: DashboardCharacterClassOption | null
}

export type DashboardProfile = {
	id: number
	name: string
	email: string
	avatar_url: string | null
	email_verified_at: string | null
	home_profile: DashboardHomeProfile
	primary_character: {
		id: number
		name: string | null
		world: string | null
		datacenter: string | null
		avatar_url: string | null
	} | null
}

export type DashboardSummary = {
	unread_notification_count: number
	verified_character_count: number
	connected_account_count: number
	group_count: number
	owned_group_count: number
	moderated_group_count: number
	member_group_count: number
	active_application_count: number
	pending_application_count: number
	confirmed_participation_count: number
	completed_participation_count: number
}

export type DashboardSetup = {
	has_primary_character: boolean
	has_verified_characters: boolean
	public_profile: boolean
	public_characters: boolean
	connected_providers: string[]
}

export type DashboardGroupLink = {
	id: number
	name: string
	slug: string
	href: string
}

export type DashboardGroupBucket = {
	count: number
	items: DashboardGroupLink[]
}

export type DashboardGroups = {
	owned: DashboardGroupBucket
	moderated: DashboardGroupBucket
	member: DashboardGroupBucket
}

export type DashboardHomeProfileOptions = {
	character_classes: DashboardCharacterClassOption[]
}

export type DashboardHomeBanner = {
	character: {
		id: number | null
		name: string
		world: string | null
		datacenter: string | null
		avatar_url: string | null
		display_job: DashboardCharacterClassOption | null
		display_job_level: number | null
	}
}

export type DashboardHomeBannerDetails = {
	last_run: {
		activity_title: string | null
		activity_type_name: LocalizedText
		activity_icon_url: string | null
		activity_icon: string
		progress: number | null
		progress_label: LocalizedText
		class_name: string | null
		class_icon_url: string | null
		phantom_job_name: string | null
		phantom_job_icon_url: string | null
		completed_at: string | null
	} | null
	next_run: {
		activity_id: number
		activity_title: string | null
		activity_type_name: LocalizedText
		starts_at: string | null
		secret_key: string | null
		group: {
			name: string | null
			slug: string | null
		}
	} | null
	weekly_participation: Array<{
		start: string
		end: string
		count: number
	}>
}

export type DashboardActivityOverviewRun = {
	id: string
	activity_id: number
	title: string | null
	activity_type_name: LocalizedText | null
	image_url: string | null
	starts_at: string | null
	status_key: string
	status_color: "success" | "warning" | "primary" | "info" | "neutral" | "error"
	group: {
		name: string | null
		slug: string | null
	}
	datacenter: string | null
	run_style: string | null
	difficulty: string | null
	href: string | null
}

export type DashboardActivityOverviewApplication = {
	id: number
	status: string
	status_key: string
	status_color: "success" | "warning" | "primary" | "info" | "neutral" | "error"
	submitted_at: string | null
	title: string | null
	activity_type_name: LocalizedText | null
	image_url: string | null
	href: string | null
	group: {
		name: string | null
		slug: string | null
	}
	activity: {
		id: number | null
		starts_at: string | null
		datacenter: string | null
		run_style: string | null
		difficulty: string | null
	}
}

export type DashboardActivityOverviewGroup = {
	id: number
	name: string
	slug: string
	role: "owner" | "moderator" | "member" | string
	profile_picture_url: string | null
	last_activity_at: string | null
	last_activity_key: string
	urls: {
		group: string
		runs: string
		settings: string | null
	}
}

export type DashboardActivityOverview = {
	runs: DashboardActivityOverviewRun[]
	applications: DashboardActivityOverviewApplication[]
	groups: DashboardActivityOverviewGroup[]
	notifications: NotificationRecord[]
}

export type DashboardAccountCompletionItem = {
	key: string
	priority: "important" | "recommended"
	is_complete: boolean
}

export type DashboardAccountCompletion = {
	percent: number
	completed_count: number
	total_count: number
	should_celebrate_completion: boolean
	items: DashboardAccountCompletionItem[]
}

export type DashboardPageProps = {
	profile: DashboardProfile
	homeProfileOptions: DashboardHomeProfileOptions
	homeBanner: DashboardHomeBanner
	homeBannerDetails?: DashboardHomeBannerDetails
	homeActivityOverview?: DashboardActivityOverview
	homeAccountCompletion?: DashboardAccountCompletion
}
