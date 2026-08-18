<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CalculatorHeader from '../components/CalculatorHeader.vue'
import GearImport from '../components/GearImport.vue'
import RotationEditor from '../components/RotationEditor.vue'

const { t } = useI18n()
const selectedJob = ref<string | null>(null)
const selectedLevel = ref<number | null>(null)

function updateSelectedSet(set: { job?: string | null, level?: number | null } | null) {
	selectedJob.value = set?.job ?? null
	selectedLevel.value = set?.level ?? null
}
</script>

<template>
	<Head :title="t('calculator.title')" />

	<UApp>
		<div class="min-h-screen bg-default text-highlighted">
			<CalculatorHeader />
			<main class="px-3 py-4 sm:px-4 lg:px-5">
				<div class="grid min-h-[calc(100vh-6.5rem)] gap-4 2xl:grid-cols-[24rem_minmax(0,1fr)_minmax(20rem,0.85fr)] xl:grid-cols-[23rem_minmax(0,1fr)_minmax(18rem,0.75fr)]">
					<aside class="min-w-0">
						<GearImport @selected-set-change="updateSelectedSet" />
					</aside>

					<RotationEditor
						:job="selectedJob"
						:level="selectedLevel"
					/>
					<section class="min-h-[28rem] border border-default bg-muted" />
				</div>
			</main>
		</div>
	</UApp>
</template>
