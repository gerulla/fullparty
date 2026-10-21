import type { GroupType } from './Groups'

export interface GroupInvitePageProps {
	invite: {
		token: string
		is_system: boolean
		is_personal: boolean
		status: 'pending' | 'accepted' | 'declined' | 'revoked' | 'expired'
		uses: number
		max_uses: number | null
		expires_at: string | null
		can_accept: boolean
	}
	group: {
		id: number
		name: string
		description: string | null
		profile_picture_url: string | null
		datacenter: string
		is_visible: boolean
		group_type: GroupType
		slug: string
		member_count: number
		owner: {
			id: number | null
			name: string | null
			avatar_url: string | null
		}
		current_user_is_banned: boolean
		current_user_is_member: boolean
	}
}
