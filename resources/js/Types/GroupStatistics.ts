export type GroupStatisticsGroup = {
	id: number
	name: string
	slug: string
	current_user_role?: string | null
	permissions: {
		can_manage_group?: boolean
		can_manage_members?: boolean
		can_manage_discovery?: boolean
		can_manage_activities?: boolean
		can_view_members?: boolean
		can_review_membership_applications?: boolean
		can_manage_membership_application_form?: boolean
	}
}

export type GroupStatisticsCacheMeta = {
	cached_at: string | null
	expires_at: string | null
	refresh_cooldown_seconds: number
	refresh_available_at: string | null
	can_refresh: boolean
}

export type GroupStatisticsSummary = {
	total_runs: number
	runs_with_participants: number
	total_participants: number
	unique_participants: number
	average_participants_per_raid: number
	active_players_past_month: number
}

export type GroupStatisticsParticipationTrendPoint = {
	date: string
	run_count: number
	participant_count: number
}

export type GroupStatisticsStatusDistributionItem = {
	key: string
	count: number
	percent: number
}

export type GroupStatisticsApplicationMonth = {
	month: string
	total: number
	statuses: Record<string, number>
}

export type GroupStatisticsApplicationDay = {
	date: string
	count: number
}

export type GroupStatisticsLoadoutItem = {
	key: string
	label: string
	short_label: string | null
	role: string | null
	icon_url: string | null
	count: number
	percent: number
}

export type GroupStatisticsLoadoutSeries = {
	key: string
	label: string
	icon_url: string | null
	points: number[]
}

export type GroupStatisticsLoadoutStats = {
	total: number
	distribution: GroupStatisticsLoadoutItem[]
	monthly_trend: {
		months: string[]
		series: GroupStatisticsLoadoutSeries[]
	}
}

export type GroupStatisticsPayload = {
	generated_at: string
	summary: GroupStatisticsSummary
	participation_trend: GroupStatisticsParticipationTrendPoint[]
	applications: {
		total: number
		distribution: GroupStatisticsStatusDistributionItem[]
		daily_submissions: GroupStatisticsApplicationDay[]
		volume_by_month: GroupStatisticsApplicationMonth[]
	}
	classes: GroupStatisticsLoadoutStats
	phantom_jobs: GroupStatisticsLoadoutStats
}
