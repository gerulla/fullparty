<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
	RaidPlanAuthor,
	RaidPlanFightOption,
	RaidPlanLinks,
	RaidPlanVisibility,
} from '../../types/RaidPlan'
import RaidPlanInfoForm from './RaidPlanInfoForm.vue'

const { t } = useI18n()
const activeTab = defineModel<string>('activeTab', { default: 'inspector' })
const name = defineModel<string>('name', { required: true })
const description = defineModel<string>('description', { required: true })
const fightId = defineModel<number | null>('fightId', { required: true })
const visibility = defineModel<RaidPlanVisibility>('visibility', { required: true })

const props = defineProps<{
	inspectedObject: 'raidplan' | null
	fightOptions: RaidPlanFightOption[]
	author: RaidPlanAuthor | null
	links: RaidPlanLinks | null
	disabled: boolean
	errors: Partial<Record<'name' | 'description' | 'fight_id' | 'visibility', string>>
}>()

const tabs = computed(() => [
	{ label: t('planner.editor.inspector.title'), value: 'inspector', icon: 'i-lucide-sliders-horizontal' },
	{ label: t('planner.editor.inspector.layers'), value: 'layers', icon: 'i-lucide-layers-3' },
])

const layers = computed(() => [
	{ label: t('planner.editor.inspector.arena'), icon: 'i-lucide-square-dashed' },
	{ label: t('planner.editor.inspector.boss'), icon: 'i-lucide-skull' },
	{ label: t('planner.editor.inspector.players'), icon: 'i-lucide-users-round' },
	{ label: t('planner.editor.inspector.mechanics'), icon: 'i-lucide-sparkles' },
])
</script>

<template>
	<aside class="flex min-h-0 flex-col border-l border-default bg-elevated">
		<UTabs v-model="activeTab" :items="tabs" size="sm" class="p-3" />

		<div
			v-if="activeTab === 'inspector' && props.inspectedObject === 'raidplan'"
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
			v-else-if="activeTab === 'inspector'"
			class="flex flex-1 items-center justify-center p-6 text-center"
		>
			<div>
				<UIcon name="i-lucide-mouse-pointer-2" class="mx-auto mb-3 size-8 text-dimmed" />
				<p class="text-sm text-muted">{{ t('planner.editor.inspector.empty') }}</p>
			</div>
		</div>

		<div v-else class="space-y-1 px-3">
			<button
				v-for="layer in layers"
				:key="layer.label"
				type="button"
				class="flex w-full items-center gap-3 border border-transparent px-3 py-2 text-left text-sm text-muted hover:border-default hover:bg-accented hover:text-highlighted"
			>
				<UIcon :name="layer.icon" class="size-4" />
				<span class="flex-1">{{ layer.label }}</span>
				<UIcon name="i-lucide-eye" class="size-4 text-dimmed" />
			</button>
		</div>
	</aside>
</template>
