<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3'
import { useToast } from '@nuxt/ui/composables'
import axios from 'axios'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PlannerZoom } from '../../composables/usePlannerCanvasViewport'
import { usePlannerEditorLayout } from '../../composables/usePlannerEditorLayout'
import type {
	PlannerEditorTool,
	RaidPlanAuthor,
	RaidPlanBossElementComponent,
	RaidPlanFightOption,
	RaidPlanMarkerElementComponent,
	RaidPlanMarkerKey,
	RaidPlanMechanicDraft,
	RaidPlanMechanicPayload,
	RaidPlanMode,
	RaidPlanPayload,
	RaidPlanVisibility,
} from '../../types/RaidPlan'
import {
	emptyRaidPlanTimeline,
	isBossElementComponent,
	isMarkerElementComponent,
	normalizeRaidPlanTimeline,
} from '../../utils/raidPlanTimeline'
import PlannerCanvas from './PlannerCanvas.vue'
import PlannerInspectorPanel from './PlannerInspectorPanel.vue'
import PlannerLayersPanel from './PlannerLayersPanel.vue'
import PlannerResizeHandle from './PlannerResizeHandle.vue'
import PlannerTimeline from './PlannerTimeline.vue'
import PlannerToolbar from './PlannerToolbar.vue'
import PlannerToolPanel from './PlannerToolPanel.vue'

const { t } = useI18n()
const toast = useToast()
const page = usePage<{
	auth?: {
		user?: {
			id: number
			name: string
			avatar_url: string | null
			primary_character?: {
				name: string
				avatar_url: string | null
			} | null
		} | null
	}
}>()
const props = defineProps<{
	mode: RaidPlanMode
	raidPlan: RaidPlanPayload | null
	storeUrl?: string
	fightOptions: RaidPlanFightOption[]
}>()
const zoom = ref<PlannerZoom>('fit')
const selectedTool = ref<PlannerEditorTool>('select')
const inspectedObject = ref<'raidplan' | 'mechanic' | 'element' | null>(null)
const selectedElementId = ref<string | null>(null)
const elementContextMenu = ref<{
	elementId: string
	x: number
	y: number
} | null>(null)
const autoSave = ref(false)
const jsonSaving = ref(false)
const applyingSavedMechanics = ref(false)
let autoSaveTimeout: ReturnType<typeof setTimeout> | null = null
let saveQueued = false
let queuedSaveNeedsToast = false
let nextDraftKey = 1

const createDraftKey = (prefix: string, id: number | null): string => (
	id === null ? `${prefix}-draft-${nextDraftKey++}` : `${prefix}-${id}`
)

const mechanicPayloadToDraft = (
	mechanic: RaidPlanMechanicPayload,
	prefix: 'mechanic' | 'variant' = 'mechanic',
): RaidPlanMechanicDraft => ({
	key: createDraftKey(prefix, mechanic.id),
	id: mechanic.id,
	name: mechanic.name,
	type: mechanic.type,
	duration_ms: mechanic.duration_ms,
	selection_weight: mechanic.selection_weight,
	is_enabled: mechanic.is_enabled,
	timeline: normalizeRaidPlanTimeline(mechanic.timeline),
	timeline_schema_version: mechanic.timeline_schema_version,
	variants: mechanic.variants.map(variant => mechanicPayloadToDraft(variant, 'variant')),
})

const createInitialMechanics = (): RaidPlanMechanicDraft[] => {
	if (props.raidPlan?.mechanics.length) {
		return props.raidPlan.mechanics.map(mechanic => mechanicPayloadToDraft(mechanic))
	}

	return [{
		key: createDraftKey('mechanic', null),
		id: null,
		name: t('planner.editor.timeline.mechanic_number', { number: 1 }),
		type: 'fixed',
		duration_ms: 0,
		selection_weight: 1,
		is_enabled: true,
		timeline: emptyRaidPlanTimeline(),
		timeline_schema_version: 1,
		variants: [],
	}]
}

const form = useForm<{
	name: string
	description: string
	fight_id: number | null
	visibility: RaidPlanVisibility
	mechanics: RaidPlanMechanicDraft[]
}>({
	name: props.raidPlan?.name ?? t('planner.editor.toolbar.untitled_plan'),
	description: props.raidPlan?.description ?? '',
	fight_id: props.raidPlan?.fight_id ?? null,
	visibility: props.raidPlan?.visibility ?? 'unlisted',
	mechanics: createInitialMechanics(),
})
const {
	leftPanelWidth,
	rightPanelWidth,
	timelineHeight,
	adjustSize,
	startResize,
} = usePlannerEditorLayout()

const findMechanicByKey = (
	mechanics: RaidPlanMechanicDraft[],
	key: string,
): RaidPlanMechanicDraft | null => {
	for (const mechanic of mechanics) {
		if (mechanic.key === key) {
			return mechanic
		}

		const variant = mechanic.variants.find(item => item.key === key)

		if (variant) {
			return variant
		}
	}

	return null
}

const replaceMechanicByKey = (
	mechanics: RaidPlanMechanicDraft[],
	key: string,
	replacement: RaidPlanMechanicDraft,
): boolean => {
	const rootIndex = mechanics.findIndex(mechanic => mechanic.key === key)

	if (rootIndex >= 0) {
		mechanics.splice(rootIndex, 1, replacement)

		return true
	}

	for (const mechanic of mechanics) {
		const variantIndex = mechanic.variants.findIndex(variant => variant.key === key)

		if (variantIndex >= 0) {
			mechanic.variants.splice(variantIndex, 1, replacement)

			return true
		}
	}

	return false
}

const activeMechanicKey = ref(
	form.mechanics.find(mechanic => mechanic.type === 'fixed')?.key
		?? form.mechanics.flatMap(mechanic => mechanic.variants)[0]?.key
		?? '',
)
const activeMechanic = computed(() => (
	findMechanicByKey(form.mechanics, activeMechanicKey.value)
))
const inspectedMechanic = computed<RaidPlanMechanicDraft | null>({
	get: () => inspectedObject.value === 'mechanic' ? activeMechanic.value : null,
	set: (mechanic) => {
		if (!mechanic || !activeMechanicKey.value) {
			return
		}

		replaceMechanicByKey(form.mechanics, activeMechanicKey.value, mechanic)
	},
})
const inspectedMechanicIsVariant = computed(() => (
	form.mechanics.some(mechanic => (
		mechanic.variants.some(variant => variant.key === activeMechanicKey.value)
	))
))

const updateElement = (
	elementId: string,
	changes:
		| Partial<RaidPlanBossElementComponent>
		| Partial<RaidPlanMarkerElementComponent>,
): void => {
	const current = activeMechanic.value

	if (!current) {
		return
	}

	replaceMechanicByKey(form.mechanics, current.key, {
		...current,
		timeline: {
			...current.timeline,
			components: current.timeline.components.map(component => (
				component.id === elementId
					&& (
						isBossElementComponent(component)
						|| isMarkerElementComponent(component)
					)
					? { ...component, ...changes }
					: component
			)),
		},
	})
}

const selectedElement = computed<
	RaidPlanBossElementComponent | RaidPlanMarkerElementComponent | null
>({
	get: () => {
		if (!selectedElementId.value) {
			return null
		}

		const component = activeMechanic.value?.timeline.components.find(
			item => item.id === selectedElementId.value,
		)

		return component
			&& (
				isBossElementComponent(component)
				|| isMarkerElementComponent(component)
			)
			? component
			: null
	},
	set: (element) => {
		if (!element || !selectedElementId.value) {
			return
		}

		updateElement(selectedElementId.value, element)
	},
})

const editorColumns = computed(() => ({
	gridTemplateColumns: `${leftPanelWidth.value}px 3px minmax(0, 1fr) 3px ${rightPanelWidth.value}px`,
}))

const workspaceRows = computed(() => ({
	gridTemplateRows: `3rem minmax(0, 1fr) 3px ${timelineHeight.value}px`,
}))

const canEdit = computed(() => (
	props.mode === 'edit' && (props.raidPlan?.can_edit ?? true)
))
const canAddElements = computed(() => (
	canEdit.value && activeMechanic.value?.type === 'fixed'
))
const hasBeenSaved = computed(() => Boolean(props.raidPlan?.links.edit))
const saving = computed(() => form.processing || jsonSaving.value)

const author = computed<RaidPlanAuthor | null>(() => {
	if (props.raidPlan?.author) {
		return props.raidPlan.author
	}

	const user = page.props.auth?.user

	if (!user) {
		return null
	}

	return {
		id: user.id,
		name: user.primary_character?.name ?? user.name,
		avatar_url: user.primary_character?.avatar_url ?? user.avatar_url,
	}
})

const openPlanInspector = (): void => {
	inspectedObject.value = 'raidplan'
}

const inspectMechanic = (mechanicKey: string): void => {
	if (!findMechanicByKey(form.mechanics, mechanicKey)) {
		return
	}

	activeMechanicKey.value = mechanicKey
	selectedElementId.value = null
	inspectedObject.value = 'mechanic'
}

const addBossElement = (): void => {
	const current = activeMechanic.value

	if (!current || !canAddElements.value) {
		return
	}

	const element: RaidPlanBossElementComponent = {
		id: `boss-${crypto.randomUUID()}`,
		type: 'boss',
		offset_x: 0,
		offset_y: 0,
		rotation: 0,
		scale: 1,
		color: '#ef4444',
		hitbox_style: 'positionals',
	}

	replaceMechanicByKey(form.mechanics, current.key, {
		...current,
		timeline: {
			...current.timeline,
			components: [...current.timeline.components, element],
		},
	})
	selectedElementId.value = element.id
	inspectedObject.value = 'element'
}

const addMarkerElement = (marker: RaidPlanMarkerKey): void => {
	const current = activeMechanic.value

	if (!current || !canAddElements.value) {
		return
	}

	const element: RaidPlanMarkerElementComponent = {
		id: `marker-${crypto.randomUUID()}`,
		type: 'marker',
		marker_key: marker,
		offset_x: 0,
		offset_y: 0,
		rotation: 0,
		scale: 1,
	}

	replaceMechanicByKey(form.mechanics, current.key, {
		...current,
		timeline: {
			...current.timeline,
			components: [...current.timeline.components, element],
		},
	})
	selectedElementId.value = element.id
	inspectedObject.value = 'element'
}

const selectElement = (elementId: string): void => {
	const component = activeMechanic.value?.timeline.components.find(
		item => item.id === elementId,
	)

	if (
		!component
		|| (
			!isBossElementComponent(component)
			&& !isMarkerElementComponent(component)
		)
	) {
		return
	}

	selectedElementId.value = elementId
	inspectedObject.value = 'element'
}

const removeSelectedElement = (): void => {
	const current = activeMechanic.value
	const elementId = selectedElementId.value

	if (!current || !elementId || !canEdit.value) {
		return
	}

	replaceMechanicByKey(form.mechanics, current.key, {
		...current,
		timeline: {
			...current.timeline,
			components: current.timeline.components.filter(
				component => component.id !== elementId,
			),
		},
	})
	selectedElementId.value = null
	elementContextMenu.value = null
	inspectedObject.value = 'mechanic'
}

const openElementContextMenu = (
	elementId: string,
	clientX: number,
	clientY: number,
): void => {
	if (!canEdit.value) {
		return
	}

	selectElement(elementId)
	elementContextMenu.value = {
		elementId,
		x: Math.min(clientX, window.innerWidth - 180),
		y: Math.min(clientY, window.innerHeight - 48),
	}
}

const closeElementContextMenu = (): void => {
	elementContextMenu.value = null
}

const handleEditorKeydown = (event: KeyboardEvent): void => {
	if (event.key !== 'Delete' || !selectedElementId.value || !canEdit.value) {
		return
	}

	const target = event.target

	if (
		target instanceof HTMLInputElement
		|| target instanceof HTMLTextAreaElement
		|| target instanceof HTMLSelectElement
		|| (target instanceof HTMLElement && target.isContentEditable)
	) {
		return
	}

	event.preventDefault()
	removeSelectedElement()
}

const serializeMechanic = (mechanic: RaidPlanMechanicDraft) => ({
	id: mechanic.id,
	name: mechanic.name,
	type: mechanic.type,
	duration_ms: mechanic.duration_ms,
	selection_weight: mechanic.selection_weight,
	is_enabled: mechanic.is_enabled,
	timeline: mechanic.timeline,
	timeline_schema_version: mechanic.timeline_schema_version,
	variants: mechanic.variants.map(serializeMechanic),
})

const savePayload = () => ({
	name: form.name,
	description: form.description || null,
	fight_id: form.fight_id,
	visibility: form.visibility,
	mechanics: form.mechanics.map(serializeMechanic),
})

const applySavedMechanics = (
	drafts: RaidPlanMechanicDraft[],
	saved: RaidPlanMechanicPayload[],
): void => {
	applyingSavedMechanics.value = true

	try {
		const applyIds = (
			currentDrafts: RaidPlanMechanicDraft[],
			currentSaved: RaidPlanMechanicPayload[],
		): void => {
			currentDrafts.forEach((draft, index) => {
				const savedMechanic = currentSaved[index]

				if (!savedMechanic) {
					return
				}

				draft.id = savedMechanic.id
				applyIds(draft.variants, savedMechanic.variants)
			})
		}

		applyIds(drafts, saved)
	} finally {
		applyingSavedMechanics.value = false
	}
}

const showSavedToast = (): void => {
	toast.add({
		title: t('planner.editor.plan.saved'),
		icon: 'i-lucide-check',
		color: 'success',
	})
}

const showSaveFailedToast = (): void => {
	toast.add({
		title: t('planner.editor.plan.save_failed'),
		icon: 'i-lucide-triangle-alert',
		color: 'error',
	})
}

const saveExistingPlan = async (showToast: boolean): Promise<void> => {
	const editUrl = props.raidPlan?.links.edit

	if (!editUrl || !canEdit.value) {
		return
	}

	if (jsonSaving.value) {
		saveQueued = true
		queuedSaveNeedsToast ||= showToast

		return
	}

	jsonSaving.value = true
	let shouldShowToast = showToast

	try {
		do {
			saveQueued = false
			form.clearErrors()

			const response = await axios.patch<{ data: RaidPlanPayload }>(
				editUrl,
				savePayload(),
				{
					headers: {
						Accept: 'application/json',
					},
				},
			)

			applySavedMechanics(form.mechanics, response.data.data.mechanics)
			shouldShowToast ||= queuedSaveNeedsToast
			queuedSaveNeedsToast = false
		} while (saveQueued)

		if (shouldShowToast) {
			showSavedToast()
		}
	} catch (error) {
		if (axios.isAxiosError(error) && error.response?.status === 422) {
			const errors = error.response.data?.errors as Record<string, string[]> | undefined

			Object.entries(errors ?? {}).forEach(([field, messages]) => {
				form.setError(field as keyof typeof form.errors, messages[0] ?? '')
			})
		}

		showSaveFailedToast()
	} finally {
		jsonSaving.value = false
	}
}

const savePlan = (): void => {
	if (!canEdit.value || saving.value) {
		return
	}

	if (hasBeenSaved.value) {
		void saveExistingPlan(true)

		return
	}

	if (!props.storeUrl) {
		return
	}

	form
		.transform(() => savePayload())
		.post(props.storeUrl, {
			onSuccess: showSavedToast,
			onError: showSaveFailedToast,
		})
}

const scheduleAutoSave = (): void => {
	if (
		!autoSave.value
		|| !hasBeenSaved.value
		|| !canEdit.value
		|| applyingSavedMechanics.value
	) {
		return
	}

	if (autoSaveTimeout) {
		clearTimeout(autoSaveTimeout)
	}

	autoSaveTimeout = setTimeout(() => {
		autoSaveTimeout = null
		void saveExistingPlan(false)
	}, 700)
}

watch(
	[
		() => form.name,
		() => form.description,
		() => form.fight_id,
		() => form.visibility,
		() => form.mechanics,
	],
	scheduleAutoSave,
	{ deep: true, flush: 'sync' },
)

watch(autoSave, (enabled) => {
	if (typeof window !== 'undefined') {
		window.localStorage.setItem('fullparty-planner-autosave', String(enabled))
	}
})

onMounted(() => {
	autoSave.value = window.localStorage.getItem('fullparty-planner-autosave') === 'true'
	window.addEventListener('keydown', handleEditorKeydown)
	window.addEventListener('pointerdown', closeElementContextMenu)
})

onBeforeUnmount(() => {
	if (autoSaveTimeout) {
		clearTimeout(autoSaveTimeout)
	}

	window.removeEventListener('keydown', handleEditorKeydown)
	window.removeEventListener('pointerdown', closeElementContextMenu)
})
</script>

<template>
	<div
		class="grid h-[calc(100vh-var(--ui-header-height))] min-h-[640px] min-w-[1180px] overflow-hidden"
		:style="editorColumns"
	>
		<PlannerLayersPanel
			:components="activeMechanic?.timeline.components ?? []"
			:selected-element-id="selectedElementId"
			@select-element="selectElement"
			@inspect-mechanic="activeMechanicKey && inspectMechanic(activeMechanicKey)"
		/>

		<PlannerResizeHandle
			:label="t('planner.editor.resize.left')"
			orientation="vertical"
			@pointerdown="startResize('left', $event)"
			@adjust="adjustSize('left', $event)"
		/>

		<section class="grid min-h-0 min-w-0 overflow-hidden bg-default" :style="workspaceRows">
			<PlannerToolbar
				v-model:zoom="zoom"
				v-model:auto-save="autoSave"
				:plan-name="form.name"
				:can-edit="canEdit"
				:saving="saving"
				:show-auto-save="hasBeenSaved"
				@edit-plan="openPlanInspector"
				@save="savePlan"
			/>

			<div class="relative min-h-0 overflow-hidden bg-neutral-950/60">
				<PlannerToolPanel
					v-model="selectedTool"
					:can-add-elements="canAddElements"
					@add-boss="addBossElement"
					@add-marker="addMarkerElement"
				/>

				<PlannerCanvas
					:zoom="zoom"
					:mechanic="activeMechanic"
					:selected-element-id="selectedElementId"
					:editable="canEdit"
					@select-element="selectElement"
					@element-context-menu="openElementContextMenu"
					@update-element="updateElement"
				/>
			</div>

			<PlannerResizeHandle
				:label="t('planner.editor.resize.timeline')"
				orientation="horizontal"
				@pointerdown="startResize('timeline', $event)"
				@adjust="adjustSize('timeline', $event)"
			/>

			<PlannerTimeline
				v-model:mechanics="form.mechanics"
				:can-edit="canEdit"
				@inspect-mechanic="inspectMechanic"
			/>
		</section>

		<PlannerResizeHandle
			:label="t('planner.editor.resize.right')"
			orientation="vertical"
			invert
			@pointerdown="startResize('right', $event)"
			@adjust="adjustSize('right', $event)"
		/>

		<PlannerInspectorPanel
			v-model:name="form.name"
			v-model:description="form.description"
			v-model:fight-id="form.fight_id"
			v-model:visibility="form.visibility"
			v-model:mechanic="inspectedMechanic"
			v-model:element="selectedElement"
			:inspected-object="inspectedObject"
			:can-change-mechanic-type="!inspectedMechanicIsVariant"
			:fight-options="props.fightOptions"
			:author="author"
			:links="props.raidPlan?.links ?? null"
			:asset-upload-url="props.raidPlan?.links.asset_upload"
			:disabled="!canEdit"
			:errors="form.errors"
			@inspect-mechanic="inspectMechanic"
		/>

		<div
			v-if="elementContextMenu"
			data-planner-element-context-menu
			class="fixed z-50 min-w-40 border border-default bg-elevated p-1 shadow-xl"
			:style="{
				left: `${elementContextMenu.x}px`,
				top: `${elementContextMenu.y}px`,
			}"
			@pointerdown.stop
			@contextmenu.prevent
		>
			<button
				type="button"
				class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-error hover:bg-error/10"
				@click="removeSelectedElement"
			>
				<UIcon name="i-lucide-trash-2" class="size-4" />
				<span>{{ t('planner.editor.elements.remove') }}</span>
				<span class="ml-auto text-[10px] text-dimmed">Del</span>
			</button>
		</div>
	</div>
</template>
