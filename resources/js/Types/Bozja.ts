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
