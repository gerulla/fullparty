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
}

export type HolsterPairOption = {
	key: string
	label: LocalizedText
	meta?: {
		holster_type?: 'prepop' | 'refill' | null
		parent_holster_id?: number | string | null
		role?: string | null
		items?: HolsterContentItem[]
	} | null
}
