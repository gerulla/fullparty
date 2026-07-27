<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type {
	RaidPlanAuthor,
	RaidPlanBossElementComponent,
	RaidPlanFightOption,
	RaidPlanLinks,
	RaidPlanMarkerElementComponent,
	RaidPlanMechanicDraft,
	RaidPlanVisibility,
} from '../../types/RaidPlan'
import {
	isBossElementComponent,
	isMarkerElementComponent,
} from '../../utils/raidPlanTimeline'
import RaidPlanInfoForm from './RaidPlanInfoForm.vue'
import PlannerBossElementInspector from './PlannerBossElementInspector.vue'
import PlannerMarkerElementInspector from './PlannerMarkerElementInspector.vue'
import PlannerMechanicInspector from './PlannerMechanicInspector.vue'

const { t } = useI18n()
const name = defineModel<string>('name', { required: true })
const description = defineModel<string>('description', { required: true })
const fightId = defineModel<number | null>('fightId', { required: true })
const visibility = defineModel<RaidPlanVisibility>('visibility', { required: true })
const mechanic = defineModel<RaidPlanMechanicDraft | null>('mechanic', { required: true })
const element = defineModel<
	RaidPlanBossElementComponent | RaidPlanMarkerElementComponent | null
>('element', { required: true })

const props = defineProps<{
	inspectedObject: 'raidplan' | 'mechanic' | 'element' | null
	canChangeMechanicType: boolean
	fightOptions: RaidPlanFightOption[]
	author: RaidPlanAuthor | null
	links: RaidPlanLinks | null
	assetUploadUrl?: string
	disabled: boolean
	errors: Partial<Record<'name' | 'description' | 'fight_id' | 'visibility', string>>
}>()
const emit = defineEmits<{
	inspectMechanic: [mechanicKey: string]
}>()

</script>

<template>
	<aside class="flex min-h-0 flex-col border-l border-default bg-muted">
		<header class="flex items-center gap-2 border-b border-default px-4 py-3">
			<UIcon name="i-lucide-sliders-horizontal" class="size-4 text-primary" />
			<p class="text-xs font-semibold uppercase text-muted">
				{{ t('planner.editor.inspector.title') }}
			</p>
		</header>

		<div
			v-if="props.inspectedObject === 'raidplan'"
			class="min-h-0 flex-1 overflow-y-auto"
		>
			<RaidPlanInfoForm
				v-model:name="name"
				v-model:description="description"
				v-model:fight-id="fightId"
				v-model:visibility="visibility"
				:fight-options="props.fightOptions"
				:author="props.author"
				:links="props.links"
				:disabled="props.disabled"
				:errors="props.errors"
			/>
		</div>

		<div
			v-else-if="props.inspectedObject === 'mechanic' && mechanic"
			class="min-h-0 flex-1 overflow-y-auto"
		>
			<PlannerMechanicInspector
				v-model:mechanic="mechanic"
				:can-change-type="props.canChangeMechanicType"
				:asset-upload-url="props.assetUploadUrl"
				:disabled="props.disabled"
				@inspect-mechanic="emit('inspectMechanic', $event)"
			/>
		</div>

		<div
			v-else-if="
				props.inspectedObject === 'element'
					&& element
					&& isBossElementComponent(element)
			"
			class="min-h-0 flex-1 overflow-y-auto"
		>
			<PlannerBossElementInspector
				v-model:element="element"
				:disabled="props.disabled"
			/>
		</div>

		<div
			v-else-if="
				props.inspectedObject === 'element'
					&& element
					&& isMarkerElementComponent(element)
			"
			class="min-h-0 flex-1 overflow-y-auto"
		>
			<PlannerMarkerElementInspector
				v-model:element="element"
				:disabled="props.disabled"
			/>
		</div>

		<div
			v-else
			class="flex flex-1 items-center justify-center p-6 text-center"
		>
			<div>
				<UIcon name="i-lucide-mouse-pointer-2" class="mx-auto mb-3 size-8 text-dimmed" />
				<p class="text-sm text-muted">{{ t('planner.editor.inspector.empty') }}</p>
			</div>
		</div>
	</aside>
</template>
