export type GroupLeaderboardGroup = {
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

export type GroupLeaderboardCharacter = {
	id: number | null
	name: string
	world: string | null
	datacenter: string | null
	avatar_url: string | null
}

export type GroupLeaderboardCountEntry = {
	rank: number
	character: GroupLeaderboardCharacter
	count: number
	latest_activity_at: string | null
}

export type GroupLeaderboardPeriod = "all_time" | "past_6_months" | "past_30_days"

export type GroupLeaderboardHostSuccessEntry = {
	rank: number
	character: GroupLeaderboardCharacter
	hosted_runs: number
	successful_runs: number
	documented_successes: number
	auto_successes: number
	failed_runs: number
	weighted_successes: number
	success_rate: number
	weighted_success_rate: number
	performance_score: number
	latest_activity_at: string | null
}

export type GroupLeaderboardCacheMeta = {
	cached_at: string | null
	expires_at: string | null
	refresh_cooldown_seconds: number
	refresh_available_at: string | null
	can_refresh: boolean
}

export type GroupLeaderboardPayload = {
	generated_at: string
	summary: {
		total_participations: number
		ranked_participants: number
		raid_leader_participations: number
		host_participations: number
		completed_hosted_runs: number
	}
	rankings: {
		overall: GroupLeaderboardCountEntry[]
		raid_leaders: GroupLeaderboardCountEntry[]
		hosts: GroupLeaderboardCountEntry[]
		host_success: GroupLeaderboardHostSuccessEntry[]
	}
}
