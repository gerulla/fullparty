<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RaidPlanSceneComponent } from '../../types/RaidPlan'
import {
	isArenaMapComponent,
	isBossElementComponent,
	isMarkerElementComponent,
	isMarkerLayoutComponent,
} from '../../utils/raidPlanTimeline'
import { raidPlanMarkerAssets } from '../../utils/markerAssets'

const { t } = useI18n()
const props = defineProps<{
	components: RaidPlanSceneComponent[]
	selectedElementId: string | null
}>()
const emit = defineEmits<{
	selectElement: [elementId: string]
	inspectMechanic: []
}>()

const layers = computed(() => [...props.components].reverse().map(component => {
	if (isArenaMapComponent(component)) {
		return {
			id: component.id,
			label: t('planner.editor.mechanic.arena_map'),
			icon: 'i-lucide-image',
			image: null,
			selectable: false,
			section: 'arena' as const,
		}
	}

	if (isBossElementComponent(component)) {
		return {
			id: component.id,
			label: t('planner.editor.inspector.boss'),
			icon: 'i-lucide-skull',
			image: null,
			selectable: true,
			section: 'layers' as const,
		}
	}

	if (isMarkerLayoutComponent(component)) {
		return {
			id: component.id,
			label: t('planner.editor.mechanic.marker_layout'),
			icon: 'i-lucide-map-pinned',
			image: null,
			selectable: false,
			section: 'arena' as const,
		}
	}

	if (isMarkerElementComponent(component)) {
		return {
			id: component.id,
			label: t('planner.editor.inspector.marker', {
				marker: component.marker_key,
			}),
			icon: null,
			image: raidPlanMarkerAssets[component.marker_key],
			selectable: true,
			section: 'arena' as const,
		}
	}

	return {
		id: component.id,
		label: component.type,
		icon: 'i-lucide-box',
		image: null,
		selectable: false,
		section: 'layers' as const,
	}
}))

const mainLayers = computed(() => (
	layers.value.filter(layer => layer.section === 'layers')
))
const arenaLayers = computed(() => (
	layers.value.filter(layer => layer.section === 'arena')
))

const selectLayer = (layer: typeof layers.value[number]): void => {
	if (layer.selectable) {
		emit('selectElement', layer.id)

		return
	}

	emit('inspectMechanic')
}
</script>

<template>
	<aside class="flex min-h-0 flex-col border-r border-default bg-muted">
		<header class="flex items-center gap-2 border-b border-default px-4 py-3">
			<UIcon name="i-lucide-layers-3" class="size-4 text-primary" />
			<p class="text-xs font-semibold uppercase text-muted">
				{{ t('planner.editor.inspector.layers') }}
			</p>
		</header>

		<div class="min-h-0 flex-1 overflow-y-auto">
			<div class="space-y-1 p-2">
				<button
					v-for="layer in mainLayers"
					:key="layer.id"
					type="button"
					:class="[
						'flex w-full items-center gap-3 border px-3 py-2 text-left text-sm',
						props.selectedElementId === layer.id
							? 'border-primary bg-primary/10 text-highlighted'
							: 'border-transparent text-muted hover:border-default hover:bg-accented hover:text-highlighted',
					]"
					@click="selectLayer(layer)"
				>
					<UIcon :name="layer.icon ?? 'i-lucide-box'" class="size-4" />
					<span class="min-w-0 flex-1 truncate">{{ layer.label }}</span>
				</button>
			</div>

			<section class="border-t border-default">
				<header class="flex items-center gap-2 px-4 py-3">
					<UIcon name="i-lucide-map" class="size-4 text-primary" />
					<p class="text-xs font-semibold uppercase text-muted">
						{{ t('planner.editor.inspector.arena') }}
					</p>
				</header>

				<div class="space-y-1 px-2 pb-2">
					<button
						v-for="layer in arenaLayers"
						:key="layer.id"
						type="button"
						:class="[
							'flex w-full items-center gap-3 border px-3 py-2 text-left text-sm',
							props.selectedElementId === layer.id
								? 'border-primary bg-primary/10 text-highlighted'
								: 'border-transparent text-muted hover:border-default hover:bg-accented hover:text-highlighted',
						]"
						@click="selectLayer(layer)"
					>
						<img
							v-if="layer.image"
							:src="layer.image"
							:alt="layer.label"
							class="size-6 object-contain"
						>
						<UIcon v-else :name="layer.icon ?? 'i-lucide-box'" class="size-4" />
						<span class="min-w-0 flex-1 truncate">{{ layer.label }}</span>
					</button>
				</div>
			</section>

			<p v-if="layers.length === 0" class="px-3 py-4 text-center text-xs text-dimmed">
				{{ t('planner.editor.inspector.no_layers') }}
			</p>
		</div>
	</aside>
</template>
