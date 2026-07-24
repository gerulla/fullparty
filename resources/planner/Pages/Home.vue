<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PlannerEditorShell from '../components/editor/PlannerEditorShell.vue'
import PlannerHeader from '../components/PlannerHeader.vue'
import type {
	RaidPlanFightOption,
	RaidPlanMode,
	RaidPlanPayload,
} from '../types/RaidPlan'

const { t } = useI18n()
const props = defineProps<{
	mode: RaidPlanMode
	raid_plan: RaidPlanPayload | null
	store_url?: string
	fight_options: RaidPlanFightOption[]
}>()
</script>

<template>
	<Head :title="t('planner.title')" />

	<UApp>
		<div class="min-h-screen bg-default text-highlighted">
			<PlannerHeader />
			<main class="min-h-0">
				<PlannerEditorShell
					:mode="props.mode"
					:raid-plan="props.raid_plan"
					:store-url="props.store_url"
					:fight-options="props.fight_options"
				/>
			</main>
		</div>
	</UApp>
</template>
