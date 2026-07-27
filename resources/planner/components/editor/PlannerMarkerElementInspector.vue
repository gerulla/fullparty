<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { RaidPlanMarkerElementComponent } from '../../types/RaidPlan'
import { raidPlanMarkerAssets } from '../../utils/markerAssets'
import PlannerScrubNumberInput from './PlannerScrubNumberInput.vue'

const { t } = useI18n()
const element = defineModel<RaidPlanMarkerElementComponent | null>('element', {
	required: true,
})
const props = defineProps<{
	disabled: boolean
}>()

const updateNumber = (
	field: 'offset_x' | 'offset_y' | 'scale' | 'rotation',
	value: number,
): void => {
	if (!element.value || !Number.isFinite(value)) {
		return
	}

	element.value = {
		...element.value,
		[field]: value,
	}
}
</script>

<template>
	<div v-if="element" class="min-h-0">
		<section class="space-y-4 border-b border-default p-4">
			<p class="text-xs font-semibold uppercase text-dimmed">
				{{ t('planner.editor.marker_element.settings') }}
			</p>

			<div class="flex items-center gap-3 border border-default bg-default p-3">
				<img
					:src="raidPlanMarkerAssets[element.marker_key]"
					:alt="t('planner.editor.tools.marker', { marker: element.marker_key })"
					class="size-12 object-contain"
				>
				<p class="text-sm font-semibold text-highlighted">
					{{ t('planner.editor.tools.marker', { marker: element.marker_key }) }}
				</p>
			</div>

			<div class="grid grid-cols-2 gap-3">
				<UFormField :label="t('planner.editor.mechanic.offset_x')">
					<PlannerScrubNumberInput
						:model-value="element.offset_x"
						:aria-label="t('planner.editor.mechanic.offset_x')"
						:min="-1280"
						:max="1280"
						:disabled="props.disabled"
						@update:model-value="updateNumber('offset_x', $event)"
					/>
				</UFormField>

				<UFormField :label="t('planner.editor.mechanic.offset_y')">
					<PlannerScrubNumberInput
						:model-value="element.offset_y"
						:aria-label="t('planner.editor.mechanic.offset_y')"
						:min="-720"
						:max="720"
						:disabled="props.disabled"
						@update:model-value="updateNumber('offset_y', $event)"
					/>
				</UFormField>
			</div>

			<UFormField :label="t('planner.editor.boss_element.scale')">
				<PlannerScrubNumberInput
					:model-value="element.scale"
					:aria-label="t('planner.editor.boss_element.scale')"
					:min="0.1"
					:max="5"
					:step="0.05"
					:disabled="props.disabled"
					@update:model-value="updateNumber('scale', $event)"
				/>
			</UFormField>

			<UFormField :label="t('planner.editor.boss_element.rotation')">
				<PlannerScrubNumberInput
					:model-value="element.rotation"
					:aria-label="t('planner.editor.boss_element.rotation')"
					:min="-360"
					:max="360"
					:disabled="props.disabled"
					@update:model-value="updateNumber('rotation', $event)"
				/>
			</UFormField>
		</section>
	</div>
</template>
