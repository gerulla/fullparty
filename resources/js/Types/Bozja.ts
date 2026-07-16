import type { LocalizedStringRecord } from '@/Types/Common'

export type BozjaItemRecord = {
	id: number
	key: string
	category: string
	name: LocalizedStringRecord
	description: LocalizedStringRecord | null
	classification: string
	cache_weight: number
	icon_url: string | null
	sort_order: number
	is_active: boolean
	has_source_payload: boolean
}

export type BozjaHolsterType = 'prepop' | 'refill'

export type BozjaHolsterSummary = {
	id: number
	name: LocalizedStringRecord | null
	display_name: string | null
	role: 'tank' | 'healer' | 'melee dps' | 'physical ranged dps' | 'magic ranged dps' | null
	type: BozjaHolsterType
	parent_holster_id: number | null
	capacity_used: number
	max_capacity: number
	notes: string | null
	guide: string | null
	is_active: boolean
	is_default: boolean
	items: BozjaHolsterItem[]
}

export type BozjaItemOption = Pick<
	BozjaItemRecord,
	'id' | 'key' | 'category' | 'name' | 'description' | 'classification' | 'cache_weight' | 'icon_url'
> & {
	display_name: string
}

export type BozjaHolsterItem = BozjaItemOption & {
	quantity: number
}
