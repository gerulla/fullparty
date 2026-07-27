<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
	RaidPlanBossElementComponent,
	RaidPlanBossHitboxStyle,
} from '../../types/RaidPlan'
import PlannerScrubNumberInput from './PlannerScrubNumberInput.vue'

const { t } = useI18n()
const element = defineModel<RaidPlanBossElementComponent | null>('element', {
	required: true,
})
const props = defineProps<{
	disabled: boolean
}>()

const hitboxOptions = computed(() => [
	{
		label: t('planner.editor.boss_element.with_positionals'),
		value: 'positionals' satisfies RaidPlanBossHitboxStyle,
	},
	{
		label: t('planner.editor.boss_element.without_positionals'),
		value: 'no_positionals' satisfies RaidPlanBossHitboxStyle,
	},
])

const updateElement = (
	changes: Partial<RaidPlanBossElementComponent>,
): void => {
	if (!element.value) {
		return
	}

	element.value = {
		...element.value,
		...changes,
	}
}

const updateNumber = (
	field: 'offset_x' | 'offset_y' | 'scale' | 'rotation',
	value: number,
): void => {
	if (Number.isFinite(value)) {
		updateElement({ [field]: value })
	}
}

const updateColor = (value: string | undefined): void => {
	if (value && /^#[0-9a-f]{6}$/i.test(value)) {
		updateElement({ color: value })
	}
}
</script>

<template>
	<div v-if="element" class="min-h-0">
		<section class="space-y-4 border-b border-default p-4">
			<p class="text-xs font-semibold uppercase text-dimmed">
				{{ t('planner.editor.boss_element.settings') }}
			</p>

			<UFormField :label="t('planner.editor.boss_element.hitbox')">
				<USelect
					:model-value="element.hitbox_style"
					:items="hitboxOptions"
					value-key="value"
					:disabled="props.disabled"
					class="w-full"
					@update:model-value="updateElement({
						hitbox_style: $event as RaidPlanBossHitboxStyle,
					})"
				/>
			</UFormField>

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

			<UFormField :label="t('planner.editor.boss_element.color')">
				<UPopover>
					<UButton
						color="neutral"
						variant="outline"
						:disabled="props.disabled"
						class="w-full justify-start"
						:aria-label="t('planner.editor.boss_element.color')"
					>
						<span
							class="size-5 border border-default"
							:style="{ backgroundColor: element.color }"
						/>
						<span class="font-mono text-sm uppercase text-muted">
							{{ element.color }}
						</span>
					</UButton>

					<template #content>
						<div class="bg-elevated p-3">
							<UColorPicker
								:model-value="element.color"
								format="hex"
								:throttle="16"
								:disabled="props.disabled"
								@update:model-value="updateColor"
							/>
						</div>
					</template>
				</UPopover>
			</UFormField>
		</section>
	</div>
</template>
