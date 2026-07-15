export type PhantomCompositionSeverity = 'required' | 'recommended' | 'optional'

export type PhantomCompositionRuleType =
	| 'single_job_count'
	| 'job_set_total'
	| 'each_job_in_set'
	| 'any_job_in_set'
	| 'duplicate_limit'
	| 'package'

export type PhantomCompositionEditableRuleType =
	| 'single_job_count'
	| 'duplicate_limit'

export type PhantomCompositionComparison =
	| 'at_least'
	| 'exactly'
	| 'at_most'

export type PhantomCompositionScopePreset =
	| 'anywhere'
	| 'one_side'
	| 'specific_party'
	| 'either_party'
	| 'all_parties'

export type PhantomCompositionPartyId = 'A' | 'B' | 'C' | 'D' | 'E' | 'F'

export type PhantomCompositionBackendScopeType =
	| 'all_slots'
	| 'slot_group'
	| 'slot_group_set'
	| 'each_slot_group'
	| 'each_slot_group_set'

export type PhantomCompositionBackendScope = {
	type: PhantomCompositionBackendScopeType
	group_keys?: string[]
	group_sets?: string[][]
}

export type PhantomCompositionApiRule = {
	type: PhantomCompositionRuleType
	label?: string | null
	severity: PhantomCompositionSeverity
	comparison?: PhantomCompositionComparison
	target_count?: number
	scope?: PhantomCompositionBackendScope
	phantom_job_id?: number
	phantom_job_ids?: number[]
	children?: PhantomCompositionApiRule[]
}

export type PhantomCompositionApi = {
	id: number
	group_id: number
	content_key: string
	name: string
	description: string | null
	is_default: boolean
	is_active: boolean
	sort_order: number
	rules: PhantomCompositionApiRule[]
	created_at: string | null
	updated_at: string | null
}

export type PhantomCompositionMetadata = {
	content_key: string
	rule_types: PhantomCompositionRuleType[]
	severities: PhantomCompositionSeverity[]
	comparisons: PhantomCompositionComparison[]
	scope_types: PhantomCompositionBackendScopeType[]
	states: string[]
	slot_groups: Array<{
		key: string
		label: Record<string, string>
	}>
	default_group_sets: Array<{
		key: string
		label: Record<string, string>
		group_keys: string[]
	}>
}

export type PhantomCompositionIndexResponse = {
	data: PhantomCompositionApi[]
	meta: PhantomCompositionMetadata
}

export type PhantomCompositionResponse = {
	data: PhantomCompositionApi
	meta: PhantomCompositionMetadata
}

export type PhantomCompositionRulePlaceholder = {
	id: number
	label: string
	type: PhantomCompositionEditableRuleType
	severity: PhantomCompositionSeverity
	scope: PhantomCompositionScopePreset
	scope_parties: PhantomCompositionPartyId[]
	comparison: PhantomCompositionComparison
	target_count: number
	target_id: number
}

export type PhantomCompositionPlaceholder = {
	id: number
	name: string
	description: string
	is_active: boolean
	is_default: boolean
	sort_order: number
	updated_at: string | null
	updated_label: string
	rules: PhantomCompositionRulePlaceholder[]
}

export type PhantomCompositionEditorPayload = {
	name: string
	description: string
	is_active: boolean
	is_default: boolean
	rules: PhantomCompositionRulePlaceholder[]
}

export type PhantomJobOption = {
	id: number
	name: string
	max_level: number
	icon_url: string | null
	black_icon_url: string | null
	transparent_icon_url: string | null
	sprite_url: string | null
}
