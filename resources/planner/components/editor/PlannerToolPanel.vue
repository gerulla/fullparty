<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const selectedTool = ref('select')

const tools = computed(() => [
	{ value: 'select', label: t('planner.editor.tools.select'), icon: 'i-lucide-mouse-pointer-2' },
	{ value: 'player', label: t('planner.editor.tools.player'), icon: 'i-lucide-user-round' },
	{ value: 'boss', label: t('planner.editor.tools.boss'), icon: 'i-lucide-skull' },
	{ value: 'mechanic', label: t('planner.editor.tools.mechanic'), icon: 'i-lucide-sparkles' },
	{ value: 'shape', label: t('planner.editor.tools.shape'), icon: 'i-lucide-shapes' },
	{ value: 'text', label: t('planner.editor.tools.text'), icon: 'i-lucide-type' },
])
</script>

<template>
	<aside class="flex min-h-0 flex-col border-r border-default bg-elevated">
		<header class="border-b border-default px-4 py-3">
			<p class="text-xs font-semibold uppercase text-muted">{{ t('planner.editor.tools.title') }}</p>
		</header>

		<div class="grid grid-cols-2 gap-2 p-3">
			<UButton
				v-for="tool in tools"
				:key="tool.value"
				:label="tool.label"
				:icon="tool.icon"
				:color="selectedTool === tool.value ? 'primary' : 'neutral'"
				:variant="selectedTool === tool.value ? 'soft' : 'ghost'"
				orientation="vertical"
				class="h-20 justify-center"
				@click="selectedTool = tool.value"
			/>
		</div>

		<USeparator />

		<section class="space-y-3 p-4">
			<p class="text-xs font-semibold uppercase text-muted">{{ t('planner.editor.tools.library') }}</p>
			<UButton
				:label="t('planner.editor.tools.arena')"
				icon="i-lucide-box-select"
				color="neutral"
				variant="ghost"
				block
				disabled
			/>
			<UButton
				:label="t('planner.editor.tools.markers')"
				icon="i-lucide-map-pin"
				color="neutral"
				variant="ghost"
				block
				disabled
			/>
		</section>
	</aside>
</template>
