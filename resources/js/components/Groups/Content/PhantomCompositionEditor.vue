<script setup lang="ts">
import type {
	PhantomCompositionComparison,
	PhantomCompositionEditorPayload,
	PhantomCompositionPartyId,
	PhantomCompositionPlaceholder,
	PhantomCompositionRulePlaceholder,
	PhantomCompositionRuleType,
	PhantomCompositionScopePreset,
	PhantomCompositionSeverity,
	PhantomJobOption,
} from '@/Types/PhantomComposition'
import type { TableColumn } from '@nuxt/ui'
import { translatePhantomJobName } from '@/utils/characterJobTranslations'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
	composition: PhantomCompositionPlaceholder | null
	isCreating: boolean
	nextRuleId: number
	phantomJobs: PhantomJobOption[]
	isSaving?: boolean
}>()

const emit = defineEmits<{
	save: [payload: PhantomCompositionEditorPayload]
	delete: [compositionId: number]
	addRule: []
}>()

const { t } = useI18n()
const selectedTab = ref<'metadata' | 'rules' | 'summary'>('metadata')
const expandedRules = ref<Record<string, boolean>>({})
const draggedRuleId = ref<number | null>(null)
const dragOverRuleId = ref<number | null>(null)
const jobEditorRuleIds = ref<Record<number, boolean>>({})
const jobSearchTerms = ref<Record<number, string>>({})
const scopeEditorStates = ref<Record<number, {
	scope: PhantomCompositionScopePreset | null
	parties: PhantomCompositionPartyId[]
}>>({})

const draft = reactive<PhantomCompositionEditorPayload>({
	name: '',
	description: '',
	is_active: true,
	is_default: false,
	rules: [],
})

const cloneRule = (rule: PhantomCompositionRulePlaceholder): PhantomCompositionRulePlaceholder => ({
	...rule,
	scope_parties: [...rule.scope_parties],
})

const resetDraft = () => {
	draft.name = props.isCreating ? '' : props.composition?.name ?? ''
	draft.description = props.isCreating ? '' : props.composition?.description ?? ''
	draft.is_active = props.isCreating ? true : props.composition?.is_active ?? true
	draft.is_default = props.isCreating ? false : props.composition?.is_default ?? false
	draft.rules = props.isCreating
		? []
		: (props.composition?.rules ?? []).map(cloneRule)
	expandedRules.value = {}
	jobEditorRuleIds.value = {}
	jobSearchTerms.value = {}
	scopeEditorStates.value = {}
	selectedTab.value = 'metadata'
}

watch(
	() => [props.composition?.id, props.isCreating],
	resetDraft,
	{ immediate: true },
)

const title = computed(() => {
	if (props.isCreating) {
		return t('groups.index.content.forked_tower_blood.compositions.editor.new_title')
	}

	return props.composition?.name || t('groups.index.content.forked_tower_blood.compositions.editor.untitled')
})

const canEdit = computed(() => props.isCreating || props.composition !== null)

const editorTabs = computed(() => [
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.tabs.metadata'),
		value: 'metadata',
		icon: 'i-lucide-file-text',
	},
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.tabs.rules'),
		value: 'rules',
		icon: 'i-lucide-list-checks',
	},
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.tabs.summary'),
		value: 'summary',
		icon: 'i-lucide-chart-no-axes-column',
	},
])

const ruleTypeOptions = computed<{ label: string, value: PhantomCompositionRuleType }[]>(() => [
	{ label: t('groups.index.content.forked_tower_blood.compositions.editor.rule_types.single_job_count'), value: 'single_job_count' },
	{ label: t('groups.index.content.forked_tower_blood.compositions.editor.rule_types.duplicate_limit'), value: 'duplicate_limit' },
])

const comparisonOptions = computed<{ label: string, value: PhantomCompositionComparison }[]>(() => [
	{ label: t('groups.index.content.forked_tower_blood.compositions.editor.comparisons.at_least'), value: 'at_least' },
	{ label: t('groups.index.content.forked_tower_blood.compositions.editor.comparisons.exactly'), value: 'exactly' },
	{ label: t('groups.index.content.forked_tower_blood.compositions.editor.comparisons.at_most'), value: 'at_most' },
])

const severityOptions = computed(() => [
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.severities.required'),
		value: 'required' as const,
		icon: 'i-lucide-shield-alert',
		color: 'error' as const,
	},
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.severities.recommended'),
		value: 'recommended' as const,
		icon: 'i-lucide-star',
		color: 'primary' as const,
	},
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.severities.optional'),
		value: 'optional' as const,
		icon: 'i-lucide-circle',
		color: 'neutral' as const,
	},
])

const partyIds: PhantomCompositionPartyId[] = ['A', 'B', 'C', 'D', 'E', 'F']
const sideOneParties: PhantomCompositionPartyId[] = ['A', 'B', 'C']
const sideTwoParties: PhantomCompositionPartyId[] = ['D', 'E', 'F']
const partyDisplayIds: Partial<Record<PhantomCompositionPartyId, string>> = {
	D: 'D/1',
	E: 'E/2',
	F: 'F/3',
}

const partyOptions = computed(() => partyIds.map(id => ({
	label: t('groups.index.content.forked_tower_blood.compositions.editor.parties.party', { party: partyDisplayIds[id] ?? id }),
	value: id,
})))

const scopeModeOptions = computed(() => [
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.anywhere.title'),
		description: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.anywhere.description'),
		value: 'anywhere' as const,
		icon: 'i-lucide-users',
		layout: 'wide' as const,
		tokens: [t('groups.index.content.forked_tower_blood.compositions.editor.parties.all')],
	},
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.one_side.title'),
		description: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.one_side.description'),
		value: 'one_side' as const,
		icon: 'i-lucide-git-compare',
		layout: 'wide' as const,
		tokens: ['ABC', 'D/1 E/2 F/3'],
	},
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.specific_party.title'),
		description: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.specific_party.description'),
		value: 'specific_party' as const,
		icon: 'i-lucide-user-round',
		layout: 'normal' as const,
		tokens: ['A'],
	},
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.either_party.title'),
		description: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.either_party.description'),
		value: 'either_party' as const,
		icon: 'i-lucide-list-filter',
		layout: 'normal' as const,
		tokens: ['A', 'D/1', 'F/3'],
	},
	{
		label: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.all_parties.title'),
		description: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.all_parties.description'),
		value: 'all_parties' as const,
		icon: 'i-lucide-list-checks',
		layout: 'normal' as const,
		tokens: ['A', 'D/1', 'F/3'],
	},
])

const primaryScopeModeOptions = computed(() => scopeModeOptions.value.filter(option => option.layout === 'wide'))
const partyScopeModeOptions = computed(() => scopeModeOptions.value.filter(option => option.layout === 'normal'))

const phantomJobOptions = computed(() => props.phantomJobs.map(job => ({
	label: translatePhantomJobName(t, { name: job.name }, job.name),
	name: job.name,
	iconUrl: job.transparent_icon_url || job.icon_url || job.sprite_url || null,
	maxLevel: job.max_level,
	value: job.id,
})))

const hasPhantomJobs = computed(() => phantomJobOptions.value.length > 0)

const ruleColumns = computed<TableColumn<PhantomCompositionRulePlaceholder>[]>(() => [
	{
		id: 'drag',
		header: '',
		meta: {
			class: {
				th: 'w-10',
				td: 'w-10',
			},
		},
	},
	{
		id: 'order',
		header: '#',
		meta: {
			class: {
				th: 'w-12 text-center',
				td: 'w-12 text-center',
			},
		},
	},
	{
		id: 'expand',
		header: '',
		meta: {
			class: {
				th: 'w-10',
				td: 'w-10',
			},
		},
	},
	{
		accessorKey: 'label',
		header: t('groups.index.content.forked_tower_blood.compositions.editor.fields.rule_label'),
	},
	{
		accessorKey: 'severity',
		header: t('groups.index.content.forked_tower_blood.compositions.editor.fields.severity'),
		meta: {
			class: {
				th: 'w-44',
				td: 'w-44',
			},
		},
	},
	{
		accessorKey: 'target_id',
		header: t('groups.index.content.forked_tower_blood.compositions.editor.fields.target_job'),
	},
	{
		accessorKey: 'comparison',
		header: t('groups.index.content.forked_tower_blood.compositions.editor.fields.comparison'),
		meta: {
			class: {
				th: 'w-40',
				td: 'w-40',
			},
		},
	},
	{
		accessorKey: 'target_count',
		header: t('groups.index.content.forked_tower_blood.compositions.editor.fields.target_count'),
		meta: {
			class: {
				th: 'w-32 text-right',
				td: 'w-32 text-right',
			},
		},
	},
	{
		id: 'actions',
		header: '',
		meta: {
			class: {
				th: 'w-24',
				td: 'w-24',
			},
		},
	},
])

const ruleTableMeta = computed(() => ({
	class: {
		tr: (row: { original: PhantomCompositionRulePlaceholder }) => (
			row.original.id === dragOverRuleId.value ? 'bg-primary/5' : ''
		),
	},
}))

const ruleRowId = (rule: PhantomCompositionRulePlaceholder) => String(rule.id)

const optionLabel = <T extends string | number>(options: { label: string, value: T }[], value: T): string => (
	options.find(option => option.value === value)?.label ?? String(value)
)

const ruleTypeLabel = (value: PhantomCompositionRuleType): string => optionLabel(ruleTypeOptions.value, value)
const comparisonLabel = (value: PhantomCompositionComparison): string => optionLabel(comparisonOptions.value, value)

const comparisonShortLabel = (comparison: PhantomCompositionComparison): string => {
	if (comparison === 'at_least') {
		return '>='
	}

	if (comparison === 'at_most') {
		return '<='
	}

	return '='
}

const severityDisplay = (severity: PhantomCompositionSeverity) => (
	severityOptions.value.find(option => option.value === severity) ?? severityOptions.value[0]
)

const severityMenuItems = (rule: PhantomCompositionRulePlaceholder) => [
	severityOptions.value.map(option => ({
		label: option.label,
		icon: option.icon,
		color: option.color,
		onSelect: () => {
			rule.severity = option.value
		},
	})),
]

const selectedPhantomJob = (targetId: number) => phantomJobOptions.value.find(option => option.value === targetId) ?? null

const targetJobLabel = (targetId: number): string => (
	selectedPhantomJob(targetId)?.label
	?? t('groups.index.content.forked_tower_blood.compositions.editor.unknown_job')
)

const targetJobIconUrl = (targetId: number): string | null => selectedPhantomJob(targetId)?.iconUrl ?? null

const isJobEditorOpen = (ruleId: number): boolean => jobEditorRuleIds.value[ruleId] === true

const setJobEditorOpen = (ruleId: number, isOpen: boolean) => {
	if (isOpen) {
		jobEditorRuleIds.value = { ...jobEditorRuleIds.value, [ruleId]: true }
		return
	}

	const nextState = { ...jobEditorRuleIds.value }
	delete nextState[ruleId]
	jobEditorRuleIds.value = nextState
}

const filteredPhantomJobs = (ruleId: number) => {
	const query = (jobSearchTerms.value[ruleId] ?? '').trim().toLowerCase()

	return phantomJobOptions.value
		.filter(option => query === ''
			|| option.label.toLowerCase().includes(query)
			|| option.name.toLowerCase().includes(query))
		.slice(0, 6)
}

const selectPhantomJob = (rule: PhantomCompositionRulePlaceholder, targetId: number) => {
	rule.target_id = targetId
	setJobEditorOpen(rule.id, false)
}

const scopeModeOption = (scope: PhantomCompositionScopePreset) => (
	scopeModeOptions.value.find(option => option.value === scope) ?? scopeModeOptions.value[0]
)

const formatPartyLetters = (parties: PhantomCompositionPartyId[], separator = ' / '): string => parties.join(separator)

const formatPartyNames = (parties: PhantomCompositionPartyId[]): string => (
	parties.map(party => partyOptions.value.find(option => option.value === party)?.label ?? party).join(', ')
)

const scopeRequiresParties = (scope: PhantomCompositionScopePreset | null): boolean => scope !== null && scope !== 'anywhere'

const normalizeScopeParties = (
	scope: PhantomCompositionScopePreset,
	parties: PhantomCompositionPartyId[],
): PhantomCompositionPartyId[] => {
	const validParties = parties.filter((party, index, items) => partyIds.includes(party) && items.indexOf(party) === index)

	if (scope === 'anywhere') {
		return []
	}

	if (scope === 'one_side') {
		const firstParty = validParties[0] ?? sideOneParties[0]

		return sideOneParties.includes(firstParty) ? [...sideOneParties] : [...sideTwoParties]
	}

	if (scope === 'specific_party') {
		return validParties.slice(0, 1)
	}

	return validParties
}

const defaultScopeParties = (scope: PhantomCompositionScopePreset): PhantomCompositionPartyId[] => {
	if (scope === 'one_side') {
		return [...sideOneParties]
	}

	if (scope === 'specific_party') {
		return ['A']
	}

	if (scope === 'either_party' || scope === 'all_parties') {
		return ['A', 'D', 'F']
	}

	return []
}

const ruleScopeParties = (rule: PhantomCompositionRulePlaceholder): PhantomCompositionPartyId[] => (
	normalizeScopeParties(rule.scope, rule.scope_parties ?? defaultScopeParties(rule.scope))
)

const scopeSummary = (scope: PhantomCompositionScopePreset, parties: PhantomCompositionPartyId[]) => {
	const normalizedParties = normalizeScopeParties(scope, parties)
	const mode = scopeModeOption(scope)
	const partyText = normalizedParties.length > 0
		? formatPartyLetters(normalizedParties)
		: t('groups.index.content.forked_tower_blood.compositions.editor.parties.all')

	return {
		title: t(`groups.index.content.forked_tower_blood.compositions.editor.scope_display.${scope}.title`, { parties: partyText }),
		description: t(`groups.index.content.forked_tower_blood.compositions.editor.scope_display.${scope}.description`, { parties: partyText }),
		icon: mode.icon,
	}
}

const ruleScopeSummary = (rule: PhantomCompositionRulePlaceholder) => scopeSummary(rule.scope, ruleScopeParties(rule))

const scopePlainText = (
	rule: PhantomCompositionRulePlaceholder,
	scope: PhantomCompositionScopePreset,
	parties: PhantomCompositionPartyId[],
): string => {
	const normalizedParties = normalizeScopeParties(scope, parties)
	const partyText = normalizedParties.length > 0
		? formatPartyNames(normalizedParties)
		: t('groups.index.content.forked_tower_blood.compositions.editor.parties.all')

	return t(`groups.index.content.forked_tower_blood.compositions.editor.scope_plain.${scope}`, {
		job: targetJobLabel(rule.target_id),
		comparison: comparisonLabel(rule.comparison),
		count: rule.target_count,
		parties: partyText,
	})
}

const ruleScopePlainText = (rule: PhantomCompositionRulePlaceholder) => (
	scopePlainText(rule, rule.scope, ruleScopeParties(rule))
)

const scopeEditorState = (ruleId: number) => scopeEditorStates.value[ruleId] ?? null

const scopeEditorSummary = (ruleId: number) => {
	const state = scopeEditorState(ruleId)

	return state?.scope ? scopeSummary(state.scope, state.parties) : null
}

const scopeEditorPlainText = (rule: PhantomCompositionRulePlaceholder) => {
	const state = scopeEditorState(rule.id)

	return state?.scope ? scopePlainText(rule, state.scope, state.parties) : ''
}

const startScopeEdit = (rule: PhantomCompositionRulePlaceholder) => {
	scopeEditorStates.value = {
		...scopeEditorStates.value,
		[rule.id]: {
			scope: null,
			parties: [],
		},
	}
}

const chooseScopeMode = (rule: PhantomCompositionRulePlaceholder, scope: PhantomCompositionScopePreset) => {
	const currentParties = rule.scope === scope ? ruleScopeParties(rule) : defaultScopeParties(scope)

	scopeEditorStates.value = {
		...scopeEditorStates.value,
		[rule.id]: {
			scope,
			parties: normalizeScopeParties(scope, currentParties),
		},
	}
}

const returnToScopeModeList = (ruleId: number) => {
	scopeEditorStates.value = {
		...scopeEditorStates.value,
		[ruleId]: {
			scope: null,
			parties: [],
		},
	}
}

const selectScopeParty = (ruleId: number, partyId: PhantomCompositionPartyId) => {
	const state = scopeEditorState(ruleId)

	if (!state?.scope) {
		return
	}

	if (state.scope === 'one_side') {
		state.parties = sideOneParties.includes(partyId) ? [...sideOneParties] : [...sideTwoParties]
		return
	}

	if (state.scope === 'specific_party') {
		state.parties = [partyId]
		return
	}

	const isSelected = state.parties.includes(partyId)
	state.parties = isSelected
		? state.parties.filter(party => party !== partyId)
		: [...state.parties, partyId]
}

const isScopePartySelected = (ruleId: number, partyId: PhantomCompositionPartyId): boolean => (
	scopeEditorState(ruleId)?.parties.includes(partyId) ?? false
)

const hasValidScopeSelection = (ruleId: number): boolean => {
	const state = scopeEditorState(ruleId)

	return Boolean(state?.scope && (!scopeRequiresParties(state.scope) || state.parties.length > 0))
}

const confirmScopeSelection = (rule: PhantomCompositionRulePlaceholder) => {
	const state = scopeEditorState(rule.id)

	if (!state?.scope || !hasValidScopeSelection(rule.id)) {
		return
	}

	rule.scope = state.scope
	rule.scope_parties = normalizeScopeParties(state.scope, state.parties)

	const nextState = { ...scopeEditorStates.value }
	delete nextState[rule.id]
	scopeEditorStates.value = nextState
}

const cancelScopeEdit = (ruleId: number) => {
	const nextState = { ...scopeEditorStates.value }
	delete nextState[ruleId]
	scopeEditorStates.value = nextState
}

const summaryRequirementRows = computed(() => draft.rules.map((rule) => {
	const parties = ruleScopeParties(rule)
	const scope = ruleScopeSummary(rule)
	const job = selectedPhantomJob(rule.target_id)
	const severity = severityDisplay(rule.severity)

	return {
		key: `${rule.id}-${rule.scope}-${parties.join('|')}-${rule.target_id}`,
		scopeKey: `${rule.scope}:${parties.join('|')}`,
		scopeLabel: scope.title,
		scopeDescription: scope.description,
		itemLabel: targetJobLabel(rule.target_id),
		itemIconUrl: job?.iconUrl ?? null,
		comparisonLabel: comparisonLabel(rule.comparison),
		comparisonShortLabel: comparisonShortLabel(rule.comparison),
		targetCount: rule.target_count,
		severityLabel: severity.label,
		severityIcon: severity.icon,
		severityColor: severity.color,
		description: ruleScopePlainText(rule),
	}
}))

const groupedSummaryRequirementRows = computed(() => {
	const groups = new Map<string, {
		key: string
		label: string
		description: string
		requirements: Array<(typeof summaryRequirementRows.value)[number]>
	}>()

	for (const requirement of summaryRequirementRows.value) {
		const group = groups.get(requirement.scopeKey)

		if (group) {
			group.requirements.push(requirement)
			continue
		}

		groups.set(requirement.scopeKey, {
			key: requirement.scopeKey,
			label: requirement.scopeLabel,
			description: requirement.scopeDescription,
			requirements: [requirement],
		})
	}

	return Array.from(groups.values())
})

const addPlaceholderRule = () => {
	const firstPhantomJob = phantomJobOptions.value[0]

	if (!firstPhantomJob) {
		return
	}

	const ruleId = props.nextRuleId + draft.rules.length
	const rule: PhantomCompositionRulePlaceholder = {
		id: ruleId,
		label: t('groups.index.content.forked_tower_blood.compositions.editor.new_rule_label'),
		type: 'single_job_count',
		severity: 'recommended',
		scope: 'anywhere',
		scope_parties: [],
		comparison: 'at_least',
		target_count: 1,
		target_id: firstPhantomJob.value,
	}

	draft.rules.push(rule)
	expandedRules.value = { ...expandedRules.value, [String(ruleId)]: true }
	setJobEditorOpen(ruleId, true)
	startScopeEdit(rule)

	emit('addRule')
}

const duplicateRule = (rule: PhantomCompositionRulePlaceholder) => {
	draft.rules.push({
		...cloneRule(rule),
		id: props.nextRuleId + draft.rules.length,
		label: t('groups.index.content.forked_tower_blood.compositions.editor.copy_label', { label: rule.label }),
	})

	emit('addRule')
}

const removeRule = (ruleId: number) => {
	draft.rules = draft.rules.filter(rule => rule.id !== ruleId)
	delete expandedRules.value[String(ruleId)]
	delete jobSearchTerms.value[ruleId]
	setJobEditorOpen(ruleId, false)
	cancelScopeEdit(ruleId)
}

const trackRuleDrag = (ruleId: number) => {
	if (draggedRuleId.value !== null && draggedRuleId.value !== ruleId) {
		dragOverRuleId.value = ruleId
	}
}

const startRuleDrag = (ruleId: number, event: DragEvent) => {
	draggedRuleId.value = ruleId
	dragOverRuleId.value = null
	event.dataTransfer?.setData('text/plain', String(ruleId))

	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'move'
	}
}

const clearRuleDrag = () => {
	draggedRuleId.value = null
	dragOverRuleId.value = null
}

const dropRule = () => {
	const draggedId = draggedRuleId.value
	const targetId = dragOverRuleId.value
	clearRuleDrag()

	if (draggedId === null || targetId === null || draggedId === targetId) {
		return
	}

	const fromIndex = draft.rules.findIndex(rule => rule.id === draggedId)
	const toIndex = draft.rules.findIndex(rule => rule.id === targetId)

	if (fromIndex === -1 || toIndex === -1) {
		return
	}

	const [rule] = draft.rules.splice(fromIndex, 1)
	draft.rules.splice(toIndex, 0, rule)
}

const save = () => {
	if (!canEdit.value || draft.name.trim() === '') {
		return
	}

	emit('save', {
		name: draft.name.trim(),
		description: draft.description.trim(),
		is_active: draft.is_active,
		is_default: draft.is_default,
		rules: draft.rules.map(cloneRule),
	})
}
</script>

<template>
	<section class="min-h-96 border border-default bg-muted/20">
		<div v-if="!canEdit" class="flex min-h-96 flex-col items-center justify-center px-6 text-center">
			<UIcon name="i-lucide-panel-right-open" class="size-7 text-muted" />
			<p class="mt-3 text-sm font-medium">
				{{ t('groups.index.content.forked_tower_blood.compositions.editor.no_selection') }}
			</p>
		</div>

		<template v-else>
			<header class="flex flex-wrap items-center justify-between gap-3 border-b border-default px-5 py-4">
				<div class="min-w-0">
					<p class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">
						{{ t('groups.index.content.forked_tower_blood.compositions.editor.title') }}
					</p>
					<h2 class="mt-1 truncate text-lg font-semibold">
						{{ title }}
					</h2>
				</div>

				<div class="flex flex-wrap items-center gap-2">
					<UButton
						v-if="composition && !isCreating"
						color="error"
						variant="ghost"
						icon="i-lucide-trash-2"
						:label="t('general.delete')"
						:disabled="isSaving"
						:ui="{ base: 'rounded-none' }"
						@click="emit('delete', composition.id)"
					/>
					<UButton
						icon="i-lucide-save"
						:label="t('general.save')"
						:loading="isSaving"
						:disabled="isSaving || draft.name.trim() === ''"
						:ui="{ base: 'rounded-none' }"
						@click="save"
					/>
				</div>
			</header>

			<div class="border-b border-default px-5 py-3">
				<UTabs
					v-model="selectedTab"
					:items="editorTabs"
					:content="false"
					variant="link"
					class="w-full"
				/>
			</div>

			<div>
				<section v-if="selectedTab === 'metadata'" class="space-y-5 p-5">
					<div>
						<h3 class="font-semibold">
							{{ t('groups.index.content.forked_tower_blood.compositions.editor.basics_title') }}
						</h3>
						<p class="text-sm text-muted">
							{{ t('groups.index.content.forked_tower_blood.compositions.editor.basics_description') }}
						</p>
					</div>

					<div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_16rem]">
						<div class="space-y-4">
							<UFormField :label="t('groups.index.content.forked_tower_blood.compositions.editor.fields.name')" required>
								<UInput
									v-model="draft.name"
									class="w-full"
									:placeholder="t('groups.index.content.forked_tower_blood.compositions.editor.fields.name_placeholder')"
									:ui="{ base: 'rounded-none' }"
								/>
							</UFormField>

							<UFormField :label="t('groups.index.content.forked_tower_blood.compositions.editor.fields.description')">
								<UTextarea
									v-model="draft.description"
									:rows="4"
									class="w-full"
									:placeholder="t('groups.index.content.forked_tower_blood.compositions.editor.fields.description_placeholder')"
									:ui="{ base: 'rounded-none' }"
								/>
							</UFormField>
						</div>

						<div class="space-y-4 border border-default bg-background/50 p-4">
							<UFormField :label="t('groups.index.content.forked_tower_blood.compositions.editor.fields.active')">
								<div class="flex h-10 items-center justify-between gap-3">
									<span class="text-sm text-muted">
										{{ draft.is_active
											? t('groups.index.content.forked_tower_blood.compositions.active')
											: t('groups.index.content.forked_tower_blood.compositions.inactive') }}
									</span>
									<USwitch v-model="draft.is_active" />
								</div>
							</UFormField>

							<UFormField :label="t('groups.index.content.forked_tower_blood.compositions.editor.fields.default')">
								<div class="flex h-10 items-center justify-between gap-3">
									<span class="text-sm text-muted">
										{{ draft.is_default
											? t('groups.index.content.forked_tower_blood.compositions.editor.default_enabled')
											: t('groups.index.content.forked_tower_blood.compositions.editor.default_disabled') }}
									</span>
									<USwitch v-model="draft.is_default" />
								</div>
							</UFormField>
						</div>
					</div>
				</section>

				<section v-else-if="selectedTab === 'rules'" class="space-y-5 p-5">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div>
							<h3 class="font-semibold">
								{{ t('groups.index.content.forked_tower_blood.compositions.editor.rules_title') }}
							</h3>
							<p class="text-sm text-muted">
								{{ t('groups.index.content.forked_tower_blood.compositions.editor.rules_description') }}
							</p>
						</div>

						<UButton
							color="neutral"
							variant="subtle"
							icon="i-lucide-plus"
							:label="t('groups.index.content.forked_tower_blood.compositions.editor.add_rule')"
							:disabled="!hasPhantomJobs"
							:ui="{ base: 'rounded-none' }"
							@click="addPlaceholderRule"
						/>
					</div>

					<div
						v-if="draft.rules.length"
						class="overflow-x-auto border border-default"
						@dragover.prevent
						@drop.prevent="dropRule"
					>
						<UTable
							v-model:expanded="expandedRules"
							:data="draft.rules"
							:columns="ruleColumns"
							:get-row-id="ruleRowId"
							:meta="ruleTableMeta"
							class="min-w-[62rem]"
							:ui="{
								base: 'rounded-none',
								th: 'whitespace-nowrap',
								td: 'align-middle',
							}"
						>
							<template #drag-cell="{ row }">
								<UButton
									color="neutral"
									variant="ghost"
									icon="i-lucide-grip-vertical"
									draggable="true"
									:aria-label="t('groups.index.content.forked_tower_blood.compositions.editor.drag_rule')"
									:ui="{ base: 'cursor-grab rounded-none active:cursor-grabbing' }"
									@dragenter.prevent="trackRuleDrag(row.original.id)"
									@dragover.prevent="trackRuleDrag(row.original.id)"
									@dragstart.stop="startRuleDrag(row.original.id, $event)"
									@dragend="clearRuleDrag"
								/>
							</template>

							<template #order-cell="{ row }">
								<span
									class="text-xs font-semibold text-muted"
									@dragenter.prevent="trackRuleDrag(row.original.id)"
									@dragover.prevent="trackRuleDrag(row.original.id)"
								>
									{{ draft.rules.findIndex(rule => rule.id === row.original.id) + 1 }}
								</span>
							</template>

							<template #expand-cell="{ row }">
								<UButton
									color="neutral"
									variant="ghost"
									:icon="row.getIsExpanded() ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'"
									:aria-label="row.getIsExpanded()
										? t('groups.index.content.forked_tower_blood.compositions.editor.collapse_rule')
										: t('groups.index.content.forked_tower_blood.compositions.editor.expand_rule')"
									:ui="{ base: 'rounded-none' }"
									@dragenter.prevent="trackRuleDrag(row.original.id)"
									@dragover.prevent="trackRuleDrag(row.original.id)"
									@click="row.toggleExpanded()"
								/>
							</template>

							<template #label-cell="{ row }">
								<div
									class="min-w-48"
									@dragenter.prevent="trackRuleDrag(row.original.id)"
									@dragover.prevent="trackRuleDrag(row.original.id)"
								>
									<p class="truncate font-semibold text-highlighted">
										{{ row.original.label }}
									</p>
									<p class="mt-1 truncate text-xs text-muted">
										{{ ruleScopeSummary(row.original).title }}
									</p>
								</div>
							</template>

							<template #severity-cell="{ row }">
								<div
									@dragenter.prevent="trackRuleDrag(row.original.id)"
									@dragover.prevent="trackRuleDrag(row.original.id)"
								>
									<UDropdownMenu
										:items="severityMenuItems(row.original)"
										:content="{ align: 'start', class: 'w-44' }"
									>
										<UButton
											:color="severityDisplay(row.original.severity).color"
											variant="subtle"
											:icon="severityDisplay(row.original.severity).icon"
											trailing-icon="i-lucide-chevron-down"
											:label="severityDisplay(row.original.severity).label"
											class="w-full justify-between"
											:ui="{ base: 'rounded-none' }"
										/>
									</UDropdownMenu>
								</div>
							</template>

							<template #target_id-cell="{ row }">
								<div
									class="whitespace-nowrap text-sm font-medium"
									@dragenter.prevent="trackRuleDrag(row.original.id)"
									@dragover.prevent="trackRuleDrag(row.original.id)"
								>
									{{ targetJobLabel(row.original.target_id) }}
								</div>
							</template>

							<template #comparison-cell="{ row }">
								<div
									@dragenter.prevent="trackRuleDrag(row.original.id)"
									@dragover.prevent="trackRuleDrag(row.original.id)"
								>
									<USelect
										v-model="row.original.comparison"
										:items="comparisonOptions"
										value-key="value"
										class="w-full"
										:ui="{ base: 'rounded-none' }"
									/>
								</div>
							</template>

							<template #target_count-cell="{ row }">
								<div
									@dragenter.prevent="trackRuleDrag(row.original.id)"
									@dragover.prevent="trackRuleDrag(row.original.id)"
								>
									<UInput
										v-model.number="row.original.target_count"
										type="number"
										:min="0"
										:max="48"
										class="ml-auto w-24"
										:ui="{ base: 'rounded-none text-right font-mono' }"
									/>
								</div>
							</template>

							<template #actions-cell="{ row }">
								<div
									class="flex items-center justify-end gap-1"
									@dragenter.prevent="trackRuleDrag(row.original.id)"
									@dragover.prevent="trackRuleDrag(row.original.id)"
								>
									<UButton
										color="neutral"
										variant="ghost"
										icon="i-lucide-copy"
										:aria-label="t('groups.index.content.forked_tower_blood.compositions.editor.duplicate_rule')"
										:ui="{ base: 'rounded-none' }"
										@click="duplicateRule(row.original)"
									/>
									<UButton
										color="neutral"
										variant="ghost"
										icon="i-lucide-trash-2"
										:aria-label="t('groups.index.content.forked_tower_blood.compositions.editor.remove_rule')"
										:ui="{ base: 'rounded-none' }"
										@click="removeRule(row.original.id)"
									/>
								</div>
							</template>

							<template #expanded="{ row }">
								<div class="whitespace-normal border-t border-default bg-background/50 p-4">
									<div class="grid gap-4 xl:grid-cols-[minmax(16rem,0.9fr)_minmax(14rem,0.72fr)_minmax(20rem,1.18fr)]">
										<section class="border border-default bg-muted/20 p-4">
											<div class="flex items-start justify-between gap-3">
												<div>
													<p class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">
														{{ t('groups.index.content.forked_tower_blood.compositions.editor.fields.target_job') }}
													</p>
													<p class="mt-1 text-sm text-muted">
														{{ t('groups.index.content.forked_tower_blood.compositions.editor.job_picker.description') }}
													</p>
												</div>

												<UBadge
													color="neutral"
													variant="subtle"
													:label="t('groups.index.content.forked_tower_blood.compositions.editor.rule_number', {
														number: draft.rules.findIndex(rule => rule.id === row.original.id) + 1,
													})"
													:ui="{ base: 'rounded-none' }"
												/>
											</div>

											<div v-if="!isJobEditorOpen(row.original.id)" class="mt-4 flex items-center justify-between gap-3 border border-default bg-background/70 p-3">
												<div class="flex min-w-0 items-center gap-3">
													<div class="grid size-10 shrink-0 place-items-center border border-primary/30 bg-primary/10 text-primary">
														<img
															v-if="targetJobIconUrl(row.original.target_id)"
															:src="targetJobIconUrl(row.original.target_id) ?? undefined"
															:alt="`${targetJobLabel(row.original.target_id)} icon`"
															class="size-7 object-contain"
														>
														<span v-else class="text-[0.65rem] font-semibold text-muted">
															PJ
														</span>
													</div>
													<div class="min-w-0">
														<p class="truncate text-sm font-semibold text-highlighted">
															{{ targetJobLabel(row.original.target_id) }}
														</p>
														<p class="mt-0.5 truncate text-xs text-muted">
															{{ ruleTypeLabel(row.original.type) }}
														</p>
													</div>
												</div>

												<UButton
													color="neutral"
													variant="ghost"
													icon="i-lucide-pencil"
													:aria-label="t('general.edit')"
													:ui="{ base: 'rounded-none' }"
													@click="setJobEditorOpen(row.original.id, true)"
												/>
											</div>

											<div v-else class="mt-4 space-y-3">
												<UInput
													v-model="jobSearchTerms[row.original.id]"
													icon="i-lucide-search"
													:placeholder="t('groups.index.content.forked_tower_blood.compositions.editor.job_picker.search_placeholder')"
													class="w-full"
													:ui="{ base: 'rounded-none' }"
												/>

												<div class="max-h-56 overflow-y-auto border border-default bg-background/70">
													<button
														v-for="job in filteredPhantomJobs(row.original.id)"
														:key="job.value"
														type="button"
														class="flex w-full items-center justify-between gap-3 border-b border-default px-3 py-2 text-left last:border-b-0 hover:bg-white/[0.04]"
														@click="selectPhantomJob(row.original, job.value)"
													>
														<span class="flex min-w-0 items-center gap-3">
															<span class="grid size-8 shrink-0 place-items-center border border-default bg-muted/40 text-muted">
																<img
																	v-if="job.iconUrl"
																	:src="job.iconUrl"
																	:alt="`${job.label} icon`"
																	class="size-6 object-contain"
																>
																<span v-else class="text-[0.62rem] font-semibold">
																	PJ
																</span>
															</span>
															<span class="min-w-0">
																<span class="block truncate text-sm font-medium">
																	{{ job.label }}
																</span>
																<span class="block truncate text-xs text-muted">
																	{{ t('groups.index.content.forked_tower_blood.compositions.editor.job_picker.max_level', { level: job.maxLevel }) }}
																</span>
															</span>
														</span>
														<UIcon
															v-if="row.original.target_id === job.value"
															name="i-lucide-check"
															class="size-4 shrink-0 text-primary"
														/>
													</button>

													<div v-if="filteredPhantomJobs(row.original.id).length === 0" class="px-3 py-5 text-center text-sm text-muted">
														{{ t('groups.index.content.forked_tower_blood.compositions.editor.job_picker.no_results') }}
													</div>
												</div>
											</div>
										</section>

										<section class="border border-default bg-muted/20 p-4">
											<p class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">
												{{ t('groups.index.content.forked_tower_blood.compositions.editor.rule_details.title') }}
											</p>

											<div class="mt-4 space-y-4">
												<UFormField :label="t('groups.index.content.forked_tower_blood.compositions.editor.fields.severity')">
													<UDropdownMenu
														:items="severityMenuItems(row.original)"
														:content="{ align: 'start', class: 'w-48' }"
													>
														<UButton
															:color="severityDisplay(row.original.severity).color"
															variant="subtle"
															:icon="severityDisplay(row.original.severity).icon"
															trailing-icon="i-lucide-chevron-down"
															:label="severityDisplay(row.original.severity).label"
															class="w-full justify-between"
															:ui="{ base: 'rounded-none' }"
														/>
													</UDropdownMenu>
												</UFormField>

												<UFormField :label="t('groups.index.content.forked_tower_blood.compositions.editor.fields.comparison')">
													<USelect
														v-model="row.original.comparison"
														:items="comparisonOptions"
														value-key="value"
														class="w-full"
														:ui="{ base: 'rounded-none' }"
													/>
												</UFormField>

												<UFormField :label="t('groups.index.content.forked_tower_blood.compositions.editor.fields.target_count')">
													<UInput
														v-model.number="row.original.target_count"
														type="number"
														:min="0"
														:max="48"
														class="w-full"
														:ui="{ base: 'rounded-none' }"
													/>
												</UFormField>
											</div>
										</section>

										<section class="border border-default bg-muted/20 p-4">
											<div class="flex items-start justify-between gap-3">
												<div>
													<p class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">
														{{ t('groups.index.content.forked_tower_blood.compositions.editor.scope_builder.title') }}
													</p>
													<p class="mt-1 text-sm text-muted">
														{{ t('groups.index.content.forked_tower_blood.compositions.editor.scope_builder.description') }}
													</p>
												</div>
											</div>

											<div v-if="scopeEditorState(row.original.id) === null" class="mt-4 flex items-start justify-between gap-3 border border-default bg-background/70 p-3">
												<div class="flex min-w-0 max-w-full gap-3">
													<div class="grid size-10 shrink-0 place-items-center border border-primary/30 bg-primary/10 text-primary">
														<UIcon :name="ruleScopeSummary(row.original).icon" class="size-5" />
													</div>
													<div class="min-w-0 max-w-full">
														<p class="whitespace-normal break-words text-sm font-semibold text-highlighted">
															{{ ruleScopeSummary(row.original).title }}
														</p>
														<p class="mt-1 whitespace-normal break-words text-xs leading-5 text-muted">
															{{ ruleScopeSummary(row.original).description }}
														</p>
														<p class="mt-3 whitespace-normal break-words border-l-2 border-primary/50 pl-3 text-xs leading-5 text-toned">
															{{ ruleScopePlainText(row.original) }}
														</p>
													</div>
												</div>

												<UButton
													color="neutral"
													variant="ghost"
													icon="i-lucide-pencil"
													:aria-label="t('general.edit')"
													:ui="{ base: 'rounded-none' }"
													@click="startScopeEdit(row.original)"
												/>
											</div>

											<div v-else-if="scopeEditorState(row.original.id)?.scope === null" class="mt-4 space-y-3">
												<div class="grid gap-2 sm:grid-cols-2">
													<button
														v-for="option in primaryScopeModeOptions"
														:key="option.value"
														type="button"
														class="border border-default bg-background/70 p-3 text-left transition hover:border-primary/60 hover:bg-primary/5"
														@click="chooseScopeMode(row.original, option.value)"
													>
														<span class="flex items-start gap-3">
															<span class="grid size-9 shrink-0 place-items-center border border-primary/30 bg-primary/10 text-primary">
																<UIcon :name="option.icon" class="size-5" />
															</span>
															<span class="min-w-0">
																<span class="flex flex-wrap gap-1">
																	<span
																		v-for="token in option.tokens"
																		:key="token"
																		class="border border-default px-1.5 py-0.5 text-[0.68rem] font-semibold text-muted"
																	>
																		{{ token }}
																	</span>
																</span>
																<span class="mt-2 block text-sm font-semibold text-highlighted">
																	{{ option.label }}
																</span>
																<span class="mt-1 block text-xs leading-5 text-muted">
																	{{ option.description }}
																</span>
															</span>
														</span>
													</button>
												</div>

												<div class="grid gap-2 md:grid-cols-3">
													<button
														v-for="option in partyScopeModeOptions"
														:key="option.value"
														type="button"
														class="border border-default bg-background/70 p-3 text-left transition hover:border-primary/60 hover:bg-primary/5"
														@click="chooseScopeMode(row.original, option.value)"
													>
														<span class="block">
															<span class="mb-3 flex flex-wrap gap-1">
																<span
																	v-for="token in option.tokens"
																	:key="token"
																	class="border border-default px-1.5 py-0.5 text-[0.68rem] font-semibold text-muted"
																>
																	{{ token }}
																</span>
															</span>
															<span class="flex items-center gap-2 text-sm font-semibold text-highlighted">
																<UIcon :name="option.icon" class="size-4 text-primary" />
																{{ option.label }}
															</span>
															<span class="mt-1 block text-xs leading-5 text-muted">
																{{ option.description }}
															</span>
														</span>
													</button>
												</div>

												<UButton
													color="neutral"
													variant="ghost"
													icon="i-lucide-x"
													:label="t('general.cancel')"
													:ui="{ base: 'rounded-none' }"
													@click="cancelScopeEdit(row.original.id)"
												/>
											</div>

											<div v-else class="mt-4 space-y-3">
												<div class="flex items-start justify-between gap-3 border border-default bg-background/70 p-3">
													<div class="flex min-w-0 max-w-full gap-3">
														<div class="grid size-10 shrink-0 place-items-center border border-primary/30 bg-primary/10 text-primary">
															<UIcon :name="scopeEditorSummary(row.original.id)?.icon" class="size-5" />
														</div>
														<div class="min-w-0 max-w-full">
															<p class="whitespace-normal break-words text-sm font-semibold text-highlighted">
																{{ scopeEditorSummary(row.original.id)?.title }}
															</p>
															<p class="mt-1 whitespace-normal break-words text-xs leading-5 text-muted">
																{{ scopeEditorSummary(row.original.id)?.description }}
															</p>
															<p class="mt-3 whitespace-normal break-words border-l-2 border-primary/50 pl-3 text-xs leading-5 text-toned">
																{{ scopeEditorPlainText(row.original) }}
															</p>
														</div>
													</div>

													<UButton
														color="neutral"
														variant="ghost"
														icon="i-lucide-arrow-left"
														:aria-label="t('general.back')"
														:ui="{ base: 'rounded-none' }"
														@click="returnToScopeModeList(row.original.id)"
													/>
												</div>

												<div v-if="scopeRequiresParties(scopeEditorState(row.original.id)?.scope ?? null)" class="grid grid-cols-2 gap-2 sm:grid-cols-3">
													<button
														v-for="party in partyOptions"
														:key="party.value"
														type="button"
														class="flex items-center justify-between gap-3 border px-3 py-2 text-left transition"
														:class="isScopePartySelected(row.original.id, party.value)
															? 'border-primary bg-primary/10 text-highlighted'
															: 'border-default bg-background/70 text-muted hover:border-primary/50 hover:bg-primary/5'"
														@click="selectScopeParty(row.original.id, party.value)"
													>
														<span class="text-sm font-semibold">
															{{ party.label }}
														</span>
														<UIcon
															:name="isScopePartySelected(row.original.id, party.value)
																? 'i-lucide-square-check'
																: 'i-lucide-square'"
															class="size-4 shrink-0"
															:class="isScopePartySelected(row.original.id, party.value) ? 'text-primary' : 'text-muted'"
														/>
													</button>
												</div>

												<p v-else class="border border-dashed border-default bg-background/50 px-3 py-4 text-sm text-muted">
													{{ t('groups.index.content.forked_tower_blood.compositions.editor.scope_builder.no_party_needed') }}
												</p>

												<div class="flex justify-end">
													<UButton
														icon="i-lucide-check"
														:label="t('general.confirm')"
														:disabled="!hasValidScopeSelection(row.original.id)"
														:ui="{ base: 'rounded-none' }"
														@click="confirmScopeSelection(row.original)"
													/>
												</div>
											</div>
										</section>
									</div>
								</div>
							</template>
						</UTable>
					</div>

					<div v-else class="border border-dashed border-default px-4 py-8 text-center">
						<p class="text-sm font-medium">
							{{ t('groups.index.content.forked_tower_blood.compositions.editor.no_rules_title') }}
						</p>
						<p class="mt-1 text-xs text-muted">
							{{ t('groups.index.content.forked_tower_blood.compositions.editor.no_rules_description') }}
						</p>
					</div>
				</section>

				<section v-else class="space-y-4 p-5">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div>
							<p class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">
								{{ t('groups.index.content.forked_tower_blood.compositions.editor.summary_eyebrow') }}
							</p>
							<h3 class="mt-1 font-semibold">
								{{ t('groups.index.content.forked_tower_blood.compositions.editor.summary_title') }}
							</h3>
							<p class="mt-1 text-sm text-muted">
								{{ t('groups.index.content.forked_tower_blood.compositions.editor.summary_description') }}
							</p>
						</div>

						<UBadge
							color="neutral"
							variant="subtle"
							size="sm"
							:label="t('groups.index.content.forked_tower_blood.compositions.editor.summary.requirements_count', {
								count: summaryRequirementRows.length,
							})"
							:ui="{ base: 'rounded-none' }"
						/>
					</div>

					<div v-if="groupedSummaryRequirementRows.length" class="grid gap-2">
						<div
							v-for="group in groupedSummaryRequirementRows"
							:key="group.key"
							class="flex flex-col gap-2 border border-default bg-muted/40 px-3 py-2"
						>
							<div class="flex flex-wrap items-start justify-between gap-2">
								<div class="min-w-0">
									<p class="truncate text-xs font-semibold uppercase tracking-wide text-toned">
										{{ group.label }}
									</p>
									<p class="mt-1 whitespace-normal break-words text-xs leading-5 text-muted">
										{{ group.description }}
									</p>
								</div>

								<UBadge
									color="neutral"
									variant="subtle"
									size="sm"
									:label="t('groups.index.content.forked_tower_blood.compositions.editor.summary.requirements_count', {
										count: group.requirements.length,
									})"
									:ui="{ base: 'rounded-none' }"
								/>
							</div>

							<div class="flex flex-wrap gap-2">
								<div
									v-for="requirement in group.requirements"
									:key="requirement.key"
									class="inline-flex min-w-0 items-center gap-2 border border-default bg-background/50 px-2.5 py-1.5"
								>
									<div class="grid size-5 shrink-0 place-items-center text-muted">
										<img
											v-if="requirement.itemIconUrl"
											:src="requirement.itemIconUrl"
											:alt="`${requirement.itemLabel} icon`"
											class="size-5 object-contain"
										>
										<span v-else class="text-[0.55rem] font-semibold">
											PJ
										</span>
									</div>

									<span class="truncate text-xs font-medium text-toned">
										{{ requirement.itemLabel }}
									</span>

									<UBadge
										color="neutral"
										variant="subtle"
										size="sm"
										:label="`${requirement.comparisonShortLabel} ${requirement.targetCount}`"
										:ui="{ base: 'rounded-none' }"
									/>

									<UBadge
										:color="requirement.severityColor"
										variant="soft"
										size="sm"
										:label="requirement.severityLabel"
										:ui="{ base: 'rounded-none' }"
									/>
								</div>
							</div>
						</div>
					</div>

					<div v-else class="border border-dashed border-default px-4 py-8 text-center">
						<p class="text-sm font-medium">
							{{ t('groups.index.content.forked_tower_blood.compositions.editor.no_rules_title') }}
						</p>
						<p class="mt-1 text-xs text-muted">
							{{ t('groups.index.content.forked_tower_blood.compositions.editor.no_rules_description') }}
						</p>
					</div>
				</section>
			</div>
		</template>
	</section>
</template>
