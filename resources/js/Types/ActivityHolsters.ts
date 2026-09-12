import type { LocalizedText } from '@/Types/Common'

export type HolsterPairValue = {
	prepop_id: string
	refill_id: string
}

export type ActivitySlotFieldSelection = string | string[] | HolsterPairValue

export type HolsterContentItem = {
	key: string
	label: LocalizedText
	icon_url?: string | null
	quantity: number
	cache_weight?: number
}

export type HolsterPairOption = {
	key: string
	label: LocalizedText
	meta?: {
		holster_type?: 'prepop' | 'refill' | null
		parent_holster_id?: number | string | null
		role?: string | null
		notes?: string | null
		capacity_used?: number | null
		max_capacity?: number | null
		items?: HolsterContentItem[]
	} | null
}
