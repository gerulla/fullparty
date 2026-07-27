import type {
	RaidPlanArenaMapComponent,
	RaidPlanArenaMapDisplayMode,
	RaidPlanBossElementComponent,
	RaidPlanMarkerElementComponent,
	RaidPlanMarkerKey,
	RaidPlanMarkerLayoutComponent,
	RaidPlanMarkerLayoutType,
	RaidPlanMechanicTimeline,
	RaidPlanSceneComponent,
} from '../types/RaidPlan'

const isRecord = (value: unknown): value is Record<string, unknown> => (
	typeof value === 'object'
	&& value !== null
	&& !Array.isArray(value)
)

const numberOr = (value: unknown, fallback = 0): number => (
	typeof value === 'number' && Number.isFinite(value) ? value : fallback
)

const displayModeOrDefault = (value: unknown): RaidPlanArenaMapDisplayMode => (
	value === 'fill' || value === 'crop' ? value : 'fit'
)

const colorOrDefault = (value: unknown): string => (
	typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value)
		? value
		: '#ef4444'
)

const markerKeys: RaidPlanMarkerKey[] = ['1', '2', '3', '4', 'A', 'B', 'C', 'D']
const markerLayoutTypes: RaidPlanMarkerLayoutType[] = [
	'standard',
	'standard_flipped',
	'diamond',
	'square',
	'waymark_studio',
]

const normalizeComponent = (value: unknown): RaidPlanSceneComponent | null => {
	if (
		!isRecord(value)
		|| typeof value.id !== 'string'
		|| typeof value.type !== 'string'
	) {
		return null
	}

	if (value.type === 'boss') {
		return {
			...value,
			id: value.id,
			type: 'boss',
			offset_x: numberOr(value.offset_x),
			offset_y: numberOr(value.offset_y),
			rotation: numberOr(value.rotation),
			scale: Math.max(0.1, numberOr(value.scale, 1)),
			color: colorOrDefault(value.color),
			hitbox_style: value.hitbox_style === 'no_positionals'
				? 'no_positionals'
				: 'positionals',
		}
	}

	if (
		value.type === 'marker'
		&& typeof value.marker_key === 'string'
		&& markerKeys.includes(value.marker_key as RaidPlanMarkerKey)
	) {
		return {
			...value,
			id: value.id,
			type: 'marker',
			marker_key: value.marker_key as RaidPlanMarkerKey,
			offset_x: numberOr(value.offset_x),
			offset_y: numberOr(value.offset_y),
			rotation: numberOr(value.rotation),
			scale: Math.max(0.1, numberOr(value.scale, 1)),
		}
	}

	if (value.type === 'marker_layout') {
		return {
			...value,
			id: value.id,
			type: 'marker_layout',
			layout: markerLayoutTypes.includes(value.layout as RaidPlanMarkerLayoutType)
				? value.layout as RaidPlanMarkerLayoutType
				: 'standard',
			distance: Math.max(0, numberOr(value.distance, 120)),
			waymark_preset: typeof value.waymark_preset === 'string'
				? value.waymark_preset
				: null,
			offset_x: numberOr(value.offset_x),
			offset_y: numberOr(value.offset_y),
			rotation: numberOr(value.rotation),
		}
	}

	if (value.type !== 'arena_map') {
		return value as RaidPlanSceneComponent
	}

	return {
		...value,
		id: value.id,
		type: 'arena_map',
		image_url: typeof value.image_url === 'string' ? value.image_url : null,
		display_mode: displayModeOrDefault(value.display_mode),
		offset_x: numberOr(value.offset_x),
		offset_y: numberOr(value.offset_y),
		rotation: numberOr(value.rotation),
		crop_left: numberOr(value.crop_left),
		crop_right: numberOr(value.crop_right),
		crop_top: numberOr(value.crop_top),
		crop_bottom: numberOr(value.crop_bottom),
	}
}

export const emptyRaidPlanTimeline = (): RaidPlanMechanicTimeline => ({
	components: [],
})

export const normalizeRaidPlanTimeline = (
	value: Record<string, unknown> | unknown[],
): RaidPlanMechanicTimeline => {
	const timeline = Array.isArray(value)
		? { events: value }
		: { ...value }
	const components = Array.isArray(timeline.components)
		? timeline.components
			.map(normalizeComponent)
			.filter((component): component is RaidPlanSceneComponent => component !== null)
		: []

	return {
		...timeline,
		components,
	}
}

export const isArenaMapComponent = (
	component: RaidPlanSceneComponent,
): component is RaidPlanArenaMapComponent => component.type === 'arena_map'

export const isBossElementComponent = (
	component: RaidPlanSceneComponent,
): component is RaidPlanBossElementComponent => component.type === 'boss'

export const isMarkerElementComponent = (
	component: RaidPlanSceneComponent,
): component is RaidPlanMarkerElementComponent => component.type === 'marker'

export const isMarkerLayoutComponent = (
	component: RaidPlanSceneComponent,
): component is RaidPlanMarkerLayoutComponent => component.type === 'marker_layout'
