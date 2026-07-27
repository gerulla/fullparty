<script setup lang="ts">
import { Assets, GraphicsContext, type Texture } from 'pixi.js'
import { computed, onBeforeUnmount, onMounted, ref, toRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
	usePlannerCanvasViewport,
	type PlannerZoom,
} from '../../composables/usePlannerCanvasViewport'
import { PlannerRenderer } from '../../core/rendering/PlannerRenderer'
import { EditorScene } from '../../core/rendering/scenes/EditorScene'
import bossFaceSvg from '../../assets/bosses/boss-face.svg?raw'
import bossHitboxNoPositionalsSvg from '../../assets/bosses/boss-hitbox-no-positionals.svg?raw'
import bossHitboxSvg from '../../assets/bosses/boss-hitbox.svg?raw'
import type {
	RaidPlanBossElementComponent,
	RaidPlanMarkerKey,
	RaidPlanMarkerElementComponent,
	RaidPlanMechanicDraft,
} from '../../types/RaidPlan'
import {
	isArenaMapComponent,
	isBossElementComponent,
	isMarkerElementComponent,
	isMarkerLayoutComponent,
} from '../../utils/raidPlanTimeline'
import { raidPlanMarkerAssets } from '../../utils/markerAssets'
import { raidPlanMarkerKeys } from '../../utils/markerLayouts'

const props = defineProps<{
	zoom: PlannerZoom
	mechanic: RaidPlanMechanicDraft | null
	selectedElementId: string | null
	editable: boolean
}>()
const emit = defineEmits<{
	selectElement: [elementId: string]
	elementContextMenu: [elementId: string, clientX: number, clientY: number]
	updateElement: [
		elementId: string,
		changes:
			| Partial<RaidPlanBossElementComponent>
			| Partial<RaidPlanMarkerElementComponent>,
	]
}>()

const { t } = useI18n()
const viewport = ref<HTMLElement | null>(null)
const canvasHost = ref<HTMLElement | null>(null)
const renderer = new PlannerRenderer(
	new EditorScene(),
	t('planner.editor.canvas_label'),
)
const arenaMap = computed(() => (
	props.mechanic?.timeline.components.find(isArenaMapComponent) ?? null
))
const bossElements = computed(() => (
	props.mechanic?.timeline.components.filter(isBossElementComponent) ?? []
))
const markerElements = computed(() => (
	props.mechanic?.timeline.components.filter(isMarkerElementComponent) ?? []
))
const markerLayouts = computed(() => (
	props.mechanic?.timeline.components.filter(isMarkerLayoutComponent) ?? []
))
const bossFaceContext = new GraphicsContext().svg(bossFaceSvg)
const bossHitboxContext = new GraphicsContext().svg(bossHitboxSvg)
const bossHitboxNoPositionalsContext = new GraphicsContext()
	.svg(bossHitboxNoPositionalsSvg)
const POSITIONALS_HITBOX_OFFSET_Y = 84.03
let sceneVersion = 0
let mounted = false
let transformActive = false
const {
	stageStyle,
	viewportCursor,
	startPan,
	movePan,
	stopPan,
} = usePlannerCanvasViewport(viewport, toRef(props, 'zoom'))

onMounted(async () => {
	if (canvasHost.value) {
		await renderer.mount(canvasHost.value)
		mounted = true
		await updateScene()
	}
})

onBeforeUnmount(() => {
	mounted = false
	sceneVersion += 1
	renderer.destroy()
})

const updateScene = async (): Promise<void> => {
	const version = ++sceneVersion
	const component = arenaMap.value
	let texture: Texture | null = null
	const markers = await Promise.all(markerElements.value.map(async marker => {
		try {
			return {
				component: marker,
				texture: await Assets.load<Texture>(
					raidPlanMarkerAssets[marker.marker_key],
				),
			}
		} catch {
			return null
		}
	}))
	const markerLayoutTextures = markerLayouts.value.length > 0
		? await loadMarkerTextures()
		: null

	if (component?.image_url) {
		try {
			texture = await Assets.load<Texture>(resolveAssetUrl(component.image_url))
		} catch {
			texture = null
		}
	}

	if (!mounted || version !== sceneVersion) {
		return
	}

	renderer.updateScene(new EditorScene({
		arenaMap: component && texture
			? {
				texture,
				displayMode: component.display_mode,
				offsetX: component.offset_x,
				offsetY: component.offset_y,
				rotation: component.rotation,
				cropLeft: component.crop_left,
				cropRight: component.crop_right,
				cropTop: component.crop_top,
				cropBottom: component.crop_bottom,
			}
			: null,
		bosses: bossElements.value.map(boss => ({
			component: boss,
			context: boss.hitbox_style === 'no_positionals'
				? bossHitboxNoPositionalsContext
				: bossHitboxContext,
			faceContext: bossFaceContext,
			hitboxOffsetY: boss.hitbox_style === 'no_positionals'
				? 0
				: POSITIONALS_HITBOX_OFFSET_Y,
		})),
		markers: markers.filter(item => item !== null),
		markerLayouts: markerLayoutTextures
			? markerLayouts.value.map(markerLayout => ({
				component: markerLayout,
				textures: markerLayoutTextures,
			}))
			: [],
		selectedElementId: props.selectedElementId,
		editable: props.editable,
		onSelectElement: elementId => emit('selectElement', elementId),
		onElementContextMenu: (elementId, clientX, clientY) => (
			emit('elementContextMenu', elementId, clientX, clientY)
		),
		onChangeElement: (elementId, changes) => (
			emit('updateElement', elementId, changes)
		),
		onTransformStart: () => {
			transformActive = true
		},
		onTransformEnd: () => {
			transformActive = false
			void updateScene()
		},
		requestRender: () => renderer.render(),
	}))
}

const loadMarkerTextures = async (): Promise<Record<RaidPlanMarkerKey, Texture> | null> => {
	try {
		const entries = await Promise.all(raidPlanMarkerKeys.map(async markerKey => [
			markerKey,
			await Assets.load<Texture>(raidPlanMarkerAssets[markerKey]),
		] as const))

		return Object.fromEntries(entries) as Record<RaidPlanMarkerKey, Texture>
	} catch {
		return null
	}
}

const resolveAssetUrl = (url: string): string => {
	if (typeof window === 'undefined') {
		return url
	}

	const resolved = new URL(url, window.location.href)

	if (resolved.pathname.startsWith('/storage/planner/')) {
		return `${window.location.origin}${resolved.pathname}${resolved.search}`
	}

	return resolved.href
}

watch(
	() => [
		props.mechanic?.timeline.components,
		props.selectedElementId,
		props.editable,
	],
	() => {
		if (mounted && !transformActive) {
			void updateScene()
		}
	},
	{ deep: true },
)
</script>

<template>
	<div
		ref="viewport"
		:class="[
			'relative size-full touch-none overflow-hidden',
			viewportCursor,
		]"
		@pointerdown="startPan"
		@pointermove="movePan"
		@pointerup="stopPan"
		@pointercancel="stopPan"
	>
		<div
			ref="canvasHost"
			class="absolute left-1/2 top-1/2 overflow-hidden bg-neutral-950 shadow-lg ring-1 ring-inset ring-default"
			:style="stageStyle"
		/>
	</div>
</template>
