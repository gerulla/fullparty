<script setup lang="ts">
import PhantomCompositionEditor from '@/components/Groups/Content/PhantomCompositionEditor.vue'
import PhantomCompositionList from '@/components/Groups/Content/PhantomCompositionList.vue'
import ContentEditorSwitcher from '@/components/Groups/Content/ContentEditorSwitcher.vue'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import type {
	PhantomCompositionApi,
	PhantomCompositionApiRule,
	PhantomCompositionBackendScope,
	PhantomCompositionEditorPayload,
	PhantomCompositionIndexResponse,
	PhantomCompositionPartyId,
	PhantomCompositionPlaceholder,
	PhantomCompositionResponse,
	PhantomCompositionRulePlaceholder,
	PhantomJobOption,
} from '@/Types/PhantomComposition'
import { useToast } from '@nuxt/ui/composables'
import axios from 'axios'
import { route } from 'ziggy-js'
import { computed, nextTick, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
	groupSlug: string
	phantomJobs: PhantomJobOption[]
}>()

const { locale, t } = useI18n()
const toast = useToast()
const isBigDesktop = useBreakpoints(breakpointsTailwind).greaterOrEqual('2xl')
const compactEditorOpen = ref(false)
const editorVisible = computed(() => isBigDesktop.value || compactEditorOpen.value)
const workspaceHeading = ref<HTMLElement | null>(null)

async function focusWorkspace() {
	await nextTick()
	if (!isBigDesktop.value) {
		workspaceHeading.value?.focus({ preventScroll: true })
		workspaceHeading.value?.scrollIntoView({ block: 'start' })
	}
}

function showLibrary() {
	if (isSaving.value) return
	compactEditorOpen.value = false
	void focusWorkspace()
}

const partyIds: PhantomCompositionPartyId[] = ['A', 'B', 'C', 'D', 'E', 'F']
const sideOneParties: PhantomCompositionPartyId[] = ['A', 'B', 'C']
const sideTwoParties: PhantomCompositionPartyId[] = ['D', 'E', 'F']

const compositions = ref<PhantomCompositionPlaceholder[]>([])
const selectedCompositionId = ref<number | null>(null)
const isCreating = ref(false)
const isLoading = ref(true)
const isSaving = ref(false)
const isReordering = ref(false)
const loadError = ref<string | null>(null)
const nextRuleId = ref(1000)

const selectedComposition = computed(() => (
	compositions.value.find(composition => composition.id === selectedCompositionId.value) ?? null
))

const groupRouteParams = computed(() => props.groupSlug)

const compositionRouteParams = (compositionId: number) => ({
	group: props.groupSlug,
	phantomComposition: compositionId,
})

const showSuccessToast = (description: string) => {
	toast.add({
		title: t('general.success'),
		description,
		color: 'success',
		icon: 'i-lucide-check',
	})
}

const showErrorToast = (description: string) => {
	toast.add({
		title: t('general.error'),
		description,
		color: 'error',
		icon: 'i-lucide-alert-triangle',
	})
}

const formatUpdatedLabel = (updatedAt: string | null): string => {
	if (!updatedAt) {
		return t('groups.index.content.forked_tower_blood.compositions.samples.updated.just_now')
	}

	const date = new Date(updatedAt)

	if (Number.isNaN(date.getTime())) {
		return t('groups.index.content.forked_tower_blood.compositions.samples.updated.just_now')
	}

	const seconds = Math.round((date.getTime() - Date.now()) / 1000)
	const absoluteSeconds = Math.abs(seconds)
	const formatter = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' })

	if (absoluteSeconds < 60) {
		return formatter.format(seconds, 'second')
	}

	const minutes = Math.round(seconds / 60)

	if (absoluteSeconds < 60 * 60) {
		return formatter.format(minutes, 'minute')
	}

	const hours = Math.round(seconds / (60 * 60))

	if (absoluteSeconds < 60 * 60 * 24) {
		return formatter.format(hours, 'hour')
	}

	const days = Math.round(seconds / (60 * 60 * 24))

	return formatter.format(days, 'day')
}

const groupKeyToPartyId = (groupKey: string): PhantomCompositionPartyId | null => {
	const letter = groupKey.replace(/^party-/, '').toUpperCase()

	return partyIds.includes(letter as PhantomCompositionPartyId)
		? letter as PhantomCompositionPartyId
		: null
}

const partyIdToGroupKey = (partyId: PhantomCompositionPartyId): string => `party-${partyId.toLowerCase()}`

const uniqueParties = (parties: PhantomCompositionPartyId[]): PhantomCompositionPartyId[] => (
	partyIds.filter(party => parties.includes(party))
)

const groupKeysToParties = (groupKeys: string[] = []): PhantomCompositionPartyId[] => (
	uniqueParties(groupKeys
		.map(groupKeyToPartyId)
		.filter((party): party is PhantomCompositionPartyId => party !== null))
)

const sameParties = (left: PhantomCompositionPartyId[], right: PhantomCompositionPartyId[]): boolean => {
	const normalizedLeft = uniqueParties(left)
	const normalizedRight = uniqueParties(right)

	return normalizedLeft.length === normalizedRight.length
		&& normalizedLeft.every((party, index) => party === normalizedRight[index])
}

const scopeFromBackend = (scope?: PhantomCompositionBackendScope): {
	scope: PhantomCompositionRulePlaceholder['scope']
	scope_parties: PhantomCompositionPartyId[]
} => {
	if (!scope || scope.type === 'all_slots') {
		return { scope: 'anywhere', scope_parties: [] }
	}

	if (scope.type === 'slot_group') {
		const parties = groupKeysToParties(scope.group_keys)

		return { scope: 'specific_party', scope_parties: parties.slice(0, 1) }
	}

	if (scope.type === 'slot_group_set') {
		const parties = groupKeysToParties(scope.group_keys)

		if (sameParties(parties, sideOneParties) || sameParties(parties, sideTwoParties)) {
			return { scope: 'one_side', scope_parties: parties }
		}

		if (parties.length === 1) {
			return { scope: 'specific_party', scope_parties: parties }
		}

		return { scope: 'either_party', scope_parties: parties }
	}

	if (scope.type === 'each_slot_group_set') {
		const parties = uniqueParties((scope.group_sets ?? [])
			.flatMap(groupKeys => groupKeysToParties(groupKeys)))

		return { scope: 'all_parties', scope_parties: parties.length ? parties : [...partyIds] }
	}

	return { scope: 'all_parties', scope_parties: [...partyIds] }
}

const scopeToBackend = (rule: PhantomCompositionRulePlaceholder): PhantomCompositionBackendScope => {
	if (rule.scope === 'anywhere') {
		return { type: 'all_slots' }
	}

	if (rule.scope === 'specific_party') {
		return {
			type: 'slot_group',
			group_keys: [partyIdToGroupKey(rule.scope_parties[0] ?? 'A')],
		}
	}

	if (rule.scope === 'all_parties') {
		const parties = uniqueParties(rule.scope_parties.length ? rule.scope_parties : partyIds)

		return {
			type: 'each_slot_group_set',
			group_sets: parties.map(party => [partyIdToGroupKey(party)]),
		}
	}

	return {
		type: 'slot_group_set',
		group_keys: uniqueParties(rule.scope_parties.length ? rule.scope_parties : sideOneParties)
			.map(partyIdToGroupKey),
	}
}

const apiRuleToEditorRule = (
	rule: PhantomCompositionApiRule,
	index: number,
): PhantomCompositionRulePlaceholder | null => {
	if (rule.type === 'package') {
		return null
	}

	const targetId = Number(rule.phantom_job_id ?? rule.phantom_job_ids?.[0] ?? props.phantomJobs[0]?.id ?? 0)

	if (!targetId) {
		return null
	}

	const scope = scopeFromBackend(rule.scope)
	const editableType = rule.type === 'duplicate_limit' ? 'duplicate_limit' : 'single_job_count'

	return {
		id: index + 1,
		label: rule.label || t('groups.index.content.forked_tower_blood.compositions.editor.new_rule_label'),
		type: editableType,
		severity: rule.severity,
		scope: scope.scope,
		scope_parties: scope.scope_parties,
		comparison: editableType === 'duplicate_limit' ? 'at_most' : rule.comparison ?? 'at_least',
		target_count: Number(rule.target_count ?? 1),
		target_id: targetId,
	}
}

const apiCompositionToEditorComposition = (composition: PhantomCompositionApi): PhantomCompositionPlaceholder => ({
	id: composition.id,
	name: composition.name,
	description: composition.description ?? '',
	is_active: composition.is_active,
	is_default: composition.is_default,
	sort_order: composition.sort_order,
	updated_at: composition.updated_at,
	updated_label: formatUpdatedLabel(composition.updated_at),
	rules: (composition.rules ?? [])
		.map(apiRuleToEditorRule)
		.filter((rule): rule is PhantomCompositionRulePlaceholder => rule !== null),
})

const editorRuleToApiRule = (rule: PhantomCompositionRulePlaceholder): PhantomCompositionApiRule => ({
	type: rule.type,
	label: rule.label,
	severity: rule.severity,
	comparison: rule.type === 'duplicate_limit' ? 'at_most' : rule.comparison,
	target_count: Number(rule.target_count),
	scope: scopeToBackend(rule),
	phantom_job_id: rule.target_id,
})

const editorPayloadToApiPayload = (
	payload: PhantomCompositionEditorPayload,
	sortOrder: number,
) => ({
	name: payload.name,
	description: payload.description || null,
	is_default: payload.is_default,
	is_active: payload.is_active,
	sort_order: sortOrder,
	rules: payload.rules.map(editorRuleToApiRule),
})

const refreshCompositionsFromApi = (apiCompositions: PhantomCompositionApi[]) => {
	const nextCompositions = apiCompositions.map(apiCompositionToEditorComposition)
	compositions.value = nextCompositions

	if (nextCompositions.length === 0) {
		selectedCompositionId.value = null
		return
	}

	if (
		selectedCompositionId.value === null
		|| !nextCompositions.some(composition => composition.id === selectedCompositionId.value)
	) {
		selectedCompositionId.value = nextCompositions[0].id
	}
}

const loadCompositions = async () => {
	isLoading.value = true
	loadError.value = null

	try {
		const response = await axios.get<PhantomCompositionIndexResponse>(route(
			'groups.dashboard.content.forked-tower-blood.phantom-compositions.index',
			groupRouteParams.value,
		))

		refreshCompositionsFromApi(response.data.data)
		isCreating.value = false
	} catch {
		loadError.value = t('groups.index.content.forked_tower_blood.compositions.load_failed')
		showErrorToast(loadError.value)
	} finally {
		isLoading.value = false
	}
}

const createComposition = () => {
	if (isSaving.value || isReordering.value) return
	selectedCompositionId.value = null
	isCreating.value = true
	compactEditorOpen.value = true
	void focusWorkspace()
}

const selectComposition = (compositionId: number) => {
	if (isSaving.value || isReordering.value) return
	selectedCompositionId.value = compositionId
	isCreating.value = false
	compactEditorOpen.value = true
	void focusWorkspace()
}

const updateLocalComposition = (composition: PhantomCompositionPlaceholder) => {
	if (composition.is_default) {
		compositions.value = compositions.value.map(item => item.id === composition.id
			? item
			: { ...item, is_default: false })
	}

	const index = compositions.value.findIndex(item => item.id === composition.id)

	if (index === -1) {
		compositions.value = [...compositions.value, composition]
		return
	}

	compositions.value[index] = composition
}

const saveComposition = async (payload: PhantomCompositionEditorPayload) => {
	if (isSaving.value) {
		return
	}

	isSaving.value = true

	try {
		if (isCreating.value) {
			const response = await axios.post<PhantomCompositionResponse>(
				route('groups.dashboard.content.forked-tower-blood.phantom-compositions.store', groupRouteParams.value),
				editorPayloadToApiPayload(payload, compositions.value.length),
			)
			const composition = apiCompositionToEditorComposition(response.data.data)

			updateLocalComposition(composition)
			selectedCompositionId.value = composition.id
			isCreating.value = false
			showSuccessToast(t('groups.index.content.forked_tower_blood.compositions.saved'))

			return
		}

		const composition = selectedComposition.value

		if (!composition) {
			return
		}

		const response = await axios.put<PhantomCompositionResponse>(
			route(
				'groups.dashboard.content.forked-tower-blood.phantom-compositions.update',
				compositionRouteParams(composition.id),
			),
			editorPayloadToApiPayload(payload, composition.sort_order),
		)

		const updatedComposition = apiCompositionToEditorComposition(response.data.data)
		updateLocalComposition(updatedComposition)
		selectedCompositionId.value = updatedComposition.id
		showSuccessToast(t('groups.index.content.forked_tower_blood.compositions.saved'))
	} catch {
		showErrorToast(t('groups.index.content.forked_tower_blood.compositions.save_failed'))
	} finally {
		isSaving.value = false
	}
}

const toggleCompositionActive = async (payload: { compositionId: number, isActive: boolean }) => {
	const composition = compositions.value.find(item => item.id === payload.compositionId)

	if (!composition || isSaving.value) {
		return
	}

	const previousValue = composition.is_active
	composition.is_active = payload.isActive
	isSaving.value = true

	try {
		const response = await axios.put<PhantomCompositionResponse>(
			route(
				'groups.dashboard.content.forked-tower-blood.phantom-compositions.update',
				compositionRouteParams(composition.id),
			),
			editorPayloadToApiPayload({
				name: composition.name,
				description: composition.description,
				is_active: payload.isActive,
				is_default: composition.is_default,
				rules: composition.rules,
			}, composition.sort_order),
		)

		updateLocalComposition(apiCompositionToEditorComposition(response.data.data))
	} catch {
		composition.is_active = previousValue
		showErrorToast(t('groups.index.content.forked_tower_blood.compositions.status_update_failed'))
	} finally {
		isSaving.value = false
	}
}

const deleteComposition = async (compositionId: number) => {
	if (isSaving.value) {
		return
	}

	const deletedIndex = compositions.value.findIndex(composition => composition.id === compositionId)

	if (deletedIndex === -1) {
		return
	}

	isSaving.value = true

	try {
		await axios.delete(route(
			'groups.dashboard.content.forked-tower-blood.phantom-compositions.destroy',
			compositionRouteParams(compositionId),
		))

		compositions.value.splice(deletedIndex, 1)
		selectedCompositionId.value = compositions.value[Math.min(deletedIndex, compositions.value.length - 1)]?.id ?? null
		isCreating.value = false
		showSuccessToast(t('groups.index.content.forked_tower_blood.compositions.deleted'))
	} catch {
		showErrorToast(t('groups.index.content.forked_tower_blood.compositions.delete_failed'))
	} finally {
		isSaving.value = false
	}
}

const reserveRuleId = () => {
	nextRuleId.value++
}

const reorderCompositions = async (compositionIds: number[]) => {
	if (isReordering.value) {
		return
	}

	const originalCompositions = [...compositions.value]
	const compositionsById = new Map(compositions.value.map(composition => [composition.id, composition]))
	const reorderedCompositions = compositionIds
		.map(compositionId => compositionsById.get(compositionId))
		.filter((composition): composition is PhantomCompositionPlaceholder => Boolean(composition))

	if (reorderedCompositions.length !== compositions.value.length) {
		return
	}

	compositions.value = reorderedCompositions.map((composition, index) => ({
		...composition,
		sort_order: index,
	}))
	isReordering.value = true

	try {
		const response = await axios.put<PhantomCompositionIndexResponse>(
			route('groups.dashboard.content.forked-tower-blood.phantom-compositions.reorder', groupRouteParams.value),
			{ composition_ids: compositionIds },
		)

		refreshCompositionsFromApi(response.data.data)
	} catch {
		compositions.value = originalCompositions
		showErrorToast(t('groups.index.content.forked_tower_blood.compositions.reorder_failed'))
	} finally {
		isReordering.value = false
	}
}

onMounted(loadCompositions)
</script>

<template>
    <div class="w-full dark:[--ui-primary:var(--color-brand-400)]">
        <header ref="workspaceHeading" tabindex="-1" class="mb-5 flex scroll-mt-20 flex-wrap items-center justify-between gap-3 outline-none">
            <ContentEditorSwitcher :group-slug="groupSlug" content="forked_tower" class="md:hidden" />
            <h1 class="hidden text-2xl font-bold md:block">{{ t('groups.index.content.overview.forked_tower_title') }}</h1>
            <UButton v-if="!isBigDesktop && compactEditorOpen" color="neutral" variant="ghost" icon="i-lucide-arrow-left"
                :label="t('groups.index.content.forked_tower_blood.compositions.workspace.library')" :disabled="isSaving" class="ml-auto rounded-md" @click="showLibrary" />
            <UButton v-else :href="route('groups.dashboard.content.index', { group: groupSlug, locale }, false)" preserve-state preserve-scroll color="neutral" variant="ghost" icon="i-lucide-chevron-left"
                :label="t('groups.index.content.delubrum_reginae_savage.holsters.workspace.change_content')" class="hidden rounded-md md:inline-flex" />
        </header>
        <UAlert v-if="loadError" color="error" variant="soft" icon="i-lucide-alert-triangle" class="mb-4 rounded-lg" :title="t('general.error')" :description="loadError" />
        <section v-if="isLoading" class="flex min-h-80 items-center justify-center rounded-lg border border-default bg-elevation-1 px-6 text-center dark:bg-elevation-2">
            <div>
                <UIcon name="i-lucide-loader-circle" class="mx-auto size-6 animate-spin text-muted" />
                <p class="mt-3 text-sm text-muted">{{ t('groups.index.content.forked_tower_blood.compositions.loading') }}</p>
            </div>
        </section>
        <div v-else class="grid items-start gap-4 2xl:grid-cols-[17rem_minmax(0,1fr)]">
            <PhantomCompositionList v-show="isBigDesktop || !compactEditorOpen"
                class="mx-auto w-full max-w-4xl 2xl:max-w-none"
                :compositions="compositions" :selected-composition-id="selectedCompositionId" :is-creating="isCreating" :is-busy="isSaving || isReordering"
                @create="createComposition" @select="selectComposition" @toggle-active="toggleCompositionActive" @reorder="reorderCompositions" />
            <PhantomCompositionEditor v-show="editorVisible"
                :composition="selectedComposition" :is-creating="isCreating" :next-rule-id="nextRuleId" :phantom-jobs="phantomJobs" :is-saving="isSaving" :separate-metadata="isBigDesktop"
                @save="saveComposition" @delete="deleteComposition" @add-rule="reserveRuleId" />
        </div>
    </div>
</template>
