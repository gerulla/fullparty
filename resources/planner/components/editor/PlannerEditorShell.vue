<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3'
import { useToast } from '@nuxt/ui/composables'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PlannerZoom } from '../../composables/usePlannerCanvasViewport'
import { usePlannerEditorLayout } from '../../composables/usePlannerEditorLayout'
import type {
	RaidPlanAuthor,
	RaidPlanFightOption,
	RaidPlanMode,
	RaidPlanPayload,
	RaidPlanVisibility,
} from '../../types/RaidPlan'
import PlannerCanvas from './PlannerCanvas.vue'
import PlannerInspectorPanel from './PlannerInspectorPanel.vue'
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
const activeInspectorTab = ref('inspector')
const inspectedObject = ref<'raidplan' | null>(null)
const form = useForm<{
	name: string
	description: string
	fight_id: number | null
	visibility: RaidPlanVisibility
}>({
	name: props.raidPlan?.name ?? t('planner.editor.toolbar.untitled_plan'),
	description: props.raidPlan?.description ?? '',
	fight_id: props.raidPlan?.fight_id ?? null,
	visibility: props.raidPlan?.visibility ?? 'unlisted',
})
const {
	leftPanelWidth,
	rightPanelWidth,
	timelineHeight,
	adjustSize,
	startResize,
} = usePlannerEditorLayout()

const editorColumns = computed(() => ({
	gridTemplateColumns: `${leftPanelWidth.value}px 3px minmax(0, 1fr) 3px ${rightPanelWidth.value}px`,
}))

const workspaceRows = computed(() => ({
	gridTemplateRows: `3rem minmax(0, 1fr) 3px ${timelineHeight.value}px`,
}))

const canEdit = computed(() => (
	props.mode === 'edit' && (props.raidPlan?.can_edit ?? true)
))

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
	activeInspectorTab.value = 'inspector'
	inspectedObject.value = 'raidplan'
}

const savePlan = (): void => {
	if (!canEdit.value || form.processing) {
		return
	}

	const options = {
		onSuccess: () => {
			toast.add({
				title: t('planner.editor.plan.saved'),
				icon: 'i-lucide-check',
				color: 'success' as const,
			})
		},
	}

	if (props.raidPlan?.links.edit) {
		form.patch(props.raidPlan.links.edit, options)
		return
	}

	if (props.storeUrl) {
		form.post(props.storeUrl, options)
	}
}
</script>

<template>
	<div
		class="grid h-[calc(100vh-var(--ui-header-height))] min-h-[640px] min-w-[1180px] overflow-hidden"
		:style="editorColumns"
	>
		<PlannerToolPanel />

		<PlannerResizeHandle
			:label="t('planner.editor.resize.left')"
			orientation="vertical"
			@pointerdown="startResize('left', $event)"
			@adjust="adjustSize('left', $event)"
		/>

		<section class="grid min-h-0 bg-default" :style="workspaceRows">
			<PlannerToolbar
				v-model:zoom="zoom"
				:plan-name="form.name"
				:can-edit="canEdit"
				:saving="form.processing"
				@edit-plan="openPlanInspector"
				@save="savePlan"
			/>

			<div class="min-h-0 overflow-hidden bg-neutral-950/60">
				<PlannerCanvas :zoom="zoom" />
			</div>

			<PlannerResizeHandle
				:label="t('planner.editor.resize.timeline')"
				orientation="horizontal"
				@pointerdown="startResize('timeline', $event)"
				@adjust="adjustSize('timeline', $event)"
			/>

			<PlannerTimeline />
		</section>

		<PlannerResizeHandle
			:label="t('planner.editor.resize.right')"
			orientation="vertical"
			invert
			@pointerdown="startResize('right', $event)"
			@adjust="adjustSize('right', $event)"
		/>

		<PlannerInspectorPanel
			v-model:active-tab="activeInspectorTab"
			v-model:name="form.name"
			v-model:description="form.description"
			v-model:fight-id="form.fight_id"
			v-model:visibility="form.visibility"
			:inspected-object="inspectedObject"
			:fight-options="props.fightOptions"
			:author="author"
			:links="props.raidPlan?.links ?? null"
			:disabled="!canEdit"
			:errors="form.errors"
		/>
	</div>
</template>
