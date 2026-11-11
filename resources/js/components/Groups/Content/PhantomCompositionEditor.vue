<script setup lang="ts">
import type {
	PhantomCompositionEditorPayload,
	PhantomCompositionPlaceholder,
	PhantomCompositionRulePlaceholder,
	PhantomJobOption,
} from '@/Types/PhantomComposition'
import type { TableColumn } from '@nuxt/ui'
import { usePhantomRulePresentation } from '@/composables/usePhantomRulePresentation'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import PhantomRuleInbox from './PhantomRuleInbox.vue'
import PhantomRuleFields from './PhantomRuleFields.vue'
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import PhantomCompositionMetadataForm from './PhantomCompositionMetadataForm.vue'

const props = defineProps<{
	composition: PhantomCompositionPlaceholder | null
	isCreating: boolean
	nextRuleId: number
	phantomJobs: PhantomJobOption[]
	isSaving?: boolean
	separateMetadata?: boolean
}>()

const emit = defineEmits<{
	save: [payload: PhantomCompositionEditorPayload]
	delete: [compositionId: number]
	addRule: []
}>()

const { t } = useI18n()
const selectedTab = ref<'metadata' | 'rules' | 'summary'>('metadata')
const isDesktop = useBreakpoints(breakpointsTailwind).greaterOrEqual('xl')
const ruleInbox = ref<InstanceType<typeof PhantomRuleInbox> | null>(null)
const newRuleId = ref<number | null>(null)
const { phantomJobOptions, hasPhantomJobs, comparisonOptions, severityOptions, severityDisplay, selectedPhantomJob, targetJobLabel, comparisonLabel, comparisonShortLabel, ruleScopeParties, ruleScopeSummary, ruleScopePlainText } = usePhantomRulePresentation(() => props.phantomJobs)
const expandedRules = ref<Record<string, boolean>>({})
const draggedRuleId = ref<number | null>(null)
const dragOverRuleId = ref<number | null>(null)
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
	newRuleId.value = null
	selectedTab.value = props.separateMetadata ? 'rules' : 'metadata'
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

watch(() => props.separateMetadata, separate => {
	if (separate && selectedTab.value === 'metadata') selectedTab.value = 'rules'
})

const editorTabs = computed(() => [
	...(!props.separateMetadata ? [{
		label: t('groups.index.content.forked_tower_blood.compositions.workspace.metadata_tab'),
		value: 'metadata',
		icon: 'i-lucide-file-text',
	}] : []),
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

const nextAvailableRuleId = () => Math.max(props.nextRuleId, ...draft.rules.map(rule => rule.id + 1))

const addPlaceholderRule = () => {
	if (props.isSaving) return
	const firstPhantomJob = phantomJobOptions.value[0]

	if (!firstPhantomJob) {
		return
	}

	const ruleId = nextAvailableRuleId()
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
	newRuleId.value = ruleId
	void nextTick(() => ruleInbox.value?.openRule(ruleId))

	emit('addRule')
}

const duplicateRule = (rule: PhantomCompositionRulePlaceholder) => {
	if (props.isSaving) return
	const id = nextAvailableRuleId()
	draft.rules.push({
		...cloneRule(rule),
		id,
		label: t('groups.index.content.forked_tower_blood.compositions.editor.copy_label', { label: rule.label }),
	})
	void nextTick(() => ruleInbox.value?.openRule(id))

	emit('addRule')
}

const removeRule = (ruleId: number) => {
	if (props.isSaving) return
	draft.rules = draft.rules.filter(rule => rule.id !== ruleId)
	delete expandedRules.value[String(ruleId)]
	if (newRuleId.value === ruleId) newRuleId.value = null
}

const updateRule = (rule: PhantomCompositionRulePlaceholder) => {
    if (props.isSaving) return
    const index = draft.rules.findIndex(item => item.id === rule.id)
    if (index !== -1) draft.rules[index] = cloneRule(rule)
    if (newRuleId.value === rule.id) newRuleId.value = null
}

const moveRule = (id: number, direction: -1 | 1) => {
    if (props.isSaving) return
    const index = draft.rules.findIndex(rule => rule.id === id)
    const target = index + direction
    if (index < 0 || target < 0 || target >= draft.rules.length) return
    const [rule] = draft.rules.splice(index, 1)
    draft.rules.splice(target, 0, rule)
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
	<div class="grid min-w-0 items-start gap-4" :class="separateMetadata && canEdit ? 'grid-cols-[minmax(0,1fr)_18rem]' : 'grid-cols-1'">
	<section class="min-w-0 min-h-96 overflow-clip rounded-lg border border-default bg-elevation-1 shadow-sm dark:bg-elevation-2 dark:shadow-none">
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
					<h2 class="mt-1 break-words text-lg font-semibold">
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
						:ui="{ base: 'rounded-md' }"
						@click="emit('delete', composition.id)"
					/>
					<UButton
						icon="i-lucide-save"
						v-if="isDesktop || selectedTab !== 'rules'"
						:label="t('general.save')"
						:loading="isSaving"
						:disabled="isSaving || draft.name.trim() === ''"
						:ui="{ base: 'rounded-md' }"
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
					:ui="{ trigger: 'min-w-0 flex-1 justify-center px-1 sm:flex-none sm:px-3', leadingIcon: 'hidden sm:block', label: 'text-xs sm:text-sm' }"
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

					<PhantomCompositionMetadataForm v-model:name="draft.name" v-model:description="draft.description" v-model:active="draft.is_active" v-model:default="draft.is_default" :disabled="isSaving" />
				</section>

                <section v-else-if="selectedTab === 'rules' && !isDesktop" class="p-3 sm:p-5">
                    <PhantomRuleInbox ref="ruleInbox" :key="isCreating ? 'new' : composition?.id" :rules="draft.rules" :phantom-jobs="phantomJobs" :disabled="isSaving" :new-rule-id="newRuleId"
                        @add="addPlaceholderRule" @update="updateRule" @duplicate="duplicateRule" @remove="removeRule" @move="moveRule" />
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
							:ui="{ base: 'rounded-md' }"
							@click="addPlaceholderRule"
						/>
					</div>

					<div
						v-if="draft.rules.length"
						class="overflow-x-auto rounded-lg border border-default"
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
								base: 'rounded-md',
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
									:ui="{ base: 'cursor-grab rounded-md active:cursor-grabbing' }"
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
									:ui="{ base: 'rounded-md' }"
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
											:ui="{ base: 'rounded-md' }"
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
										:ui="{ base: 'rounded-md' }"
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
										:ui="{ base: 'rounded-md text-right font-mono' }"
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
										:ui="{ base: 'rounded-md' }"
										@click="duplicateRule(row.original)"
									/>
									<UButton
										color="neutral"
										variant="ghost"
										icon="i-lucide-trash-2"
										:aria-label="t('groups.index.content.forked_tower_blood.compositions.editor.remove_rule')"
										:ui="{ base: 'rounded-md' }"
										@click="removeRule(row.original.id)"
									/>
								</div>
							</template>

                            <template #expanded="{ row }">
                                <div class="whitespace-normal border-t border-default p-4">
                                    <PhantomRuleFields :key="row.original.id" :rule="row.original" :phantom-jobs="phantomJobs" :disabled="isSaving" :initially-editing="newRuleId === row.original.id" @update="updateRule" />
                                </div>
                            </template>
						</UTable>
					</div>

					<div v-else class="rounded-lg border border-dashed border-default px-4 py-8 text-center">
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
							:ui="{ base: 'rounded-md' }"
						/>
					</div>

					<div v-if="groupedSummaryRequirementRows.length" class="grid gap-2">
						<div
							v-for="group in groupedSummaryRequirementRows"
							:key="group.key"
							class="flex flex-col gap-2 rounded-lg border border-default bg-elevation-2 dark:bg-elevation-3 px-3 py-2"
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
									:ui="{ base: 'rounded-md' }"
								/>
							</div>

							<div class="flex flex-wrap gap-2">
								<div
									v-for="requirement in group.requirements"
									:key="requirement.key"
									class="inline-flex min-w-0 items-center gap-2 rounded-lg border border-default bg-elevation-2 dark:bg-elevation-3 px-2.5 py-1.5"
								>
									<div class="grid size-5 shrink-0 place-items-center text-muted">
										<img
											v-if="requirement.itemIconUrl"
											:src="requirement.itemIconUrl"
											:alt="t('general.image_alt.icon', { name: requirement.itemLabel })"
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
										:ui="{ base: 'rounded-md' }"
									/>

									<UBadge
										:color="requirement.severityColor"
										variant="soft"
										size="sm"
										:label="requirement.severityLabel"
										:ui="{ base: 'rounded-md' }"
									/>
								</div>
							</div>
						</div>
					</div>

					<div v-else class="rounded-lg border border-dashed border-default px-4 py-8 text-center">
						<p class="text-sm font-medium">
							{{ t('groups.index.content.forked_tower_blood.compositions.editor.no_rules_title') }}
						</p>
						<p class="mt-1 text-xs text-muted">
							{{ t('groups.index.content.forked_tower_blood.compositions.editor.no_rules_description') }}
						</p>
					</div>
				</section>
			</div>            <div v-if="!isDesktop && selectedTab === 'rules'" class="sticky bottom-0 z-10 border-t border-default bg-elevation-1 p-3 dark:bg-elevation-2">
                <UButton block icon="i-lucide-save" :label="t('general.save')" :loading="isSaving" :disabled="isSaving || !draft.name.trim()" class="min-h-11 rounded-md" @click="save" />
            </div>
		</template>
	</section>
	<aside v-if="separateMetadata && canEdit" class="sticky top-20 min-w-0 rounded-lg border border-default bg-elevation-1 p-5 shadow-sm dark:bg-elevation-2 dark:shadow-none">
		<h2 class="flex items-center gap-2 text-lg font-semibold"><UIcon name="i-lucide-settings" class="size-5 shrink-0" />{{ t('groups.index.content.forked_tower_blood.compositions.workspace.details') }}</h2>
		<p class="mt-1 mb-5 text-sm text-muted">{{ t('groups.index.content.forked_tower_blood.compositions.editor.basics_description') }}</p>
		<PhantomCompositionMetadataForm v-model:name="draft.name" v-model:description="draft.description" v-model:active="draft.is_active" v-model:default="draft.is_default" :disabled="isSaving" />
	</aside>
	</div>
</template>
