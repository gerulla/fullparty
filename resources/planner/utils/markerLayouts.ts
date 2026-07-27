import type {
	RaidPlanMarkerKey,
	RaidPlanMarkerLayoutType,
} from '../types/RaidPlan'

export interface MarkerLayoutPoint {
	x: number
	y: number
}

export interface ParsedWaymarkStudioPreset {
	points: Partial<Record<RaidPlanMarkerKey, MarkerLayoutPoint>>
	radius: number
}

interface WaymarkStudioMarker {
	X?: unknown
	Z?: unknown
	Active?: unknown
}

const waymarkStudioKeys: Record<string, RaidPlanMarkerKey> = {
	A: 'A',
	B: 'B',
	C: 'C',
	D: 'D',
	One: '1',
	Two: '2',
	Three: '3',
	Four: '4',
}

const builtInWaymarkStudioPresets: Record<
	Exclude<RaidPlanMarkerLayoutType, 'waymark_studio'>,
	string
> = {
	standard: JSON.stringify({
		A: { X: 100, Z: 88, Active: true },
		B: { X: 112, Z: 100, Active: true },
		C: { X: 100, Z: 112, Active: true },
		D: { X: 88, Z: 100, Active: true },
		One: { X: 91.235, Z: 91.235, Active: true },
		Two: { X: 108.628, Z: 91.235, Active: true },
		Three: { X: 108.628, Z: 108.628, Active: true },
		Four: { X: 91.235, Z: 108.628, Active: true },
	}),
	standard_flipped: JSON.stringify({
		A: { X: 100, Z: 88, Active: true },
		B: { X: 112, Z: 100, Active: true },
		C: { X: 100, Z: 112, Active: true },
		D: { X: 88, Z: 100, Active: true },
		One: { X: 108.628, Z: 91.235, Active: true },
		Two: { X: 108.628, Z: 108.628, Active: true },
		Three: { X: 91.235, Z: 108.628, Active: true },
		Four: { X: 91.235, Z: 91.235, Active: true },
	}),
	diamond: JSON.stringify({
		A: { X: 100, Z: 83.25, Active: true },
		B: { X: 116.75, Z: 100, Active: true },
		C: { X: 100, Z: 116.75, Active: true },
		D: { X: 83.25, Z: 100, Active: true },
		One: { X: 91, Z: 91, Active: true },
		Two: { X: 109, Z: 91, Active: true },
		Three: { X: 109, Z: 109, Active: true },
		Four: { X: 91, Z: 109, Active: true },
	}),
	square: JSON.stringify({
		A: { X: 375, Z: 521, Active: true },
		B: { X: 384, Z: 530, Active: true },
		C: { X: 375, Z: 539, Active: true },
		D: { X: 366, Z: 530, Active: true },
		One: { X: 367, Z: 522, Active: true },
		Two: { X: 383, Z: 522, Active: true },
		Three: { X: 383, Z: 538, Active: true },
		Four: { X: 367, Z: 538, Active: true },
	}),
}

export const raidPlanMarkerKeys: RaidPlanMarkerKey[] = [
	'A',
	'B',
	'C',
	'D',
	'1',
	'2',
	'3',
	'4',
]

const finiteNumber = (value: unknown): number | null => (
	typeof value === 'number' && Number.isFinite(value) ? value : null
)

export const parseWaymarkStudioPreset = (
	preset: string,
): ParsedWaymarkStudioPreset | null => {
	let parsed: unknown

	try {
		parsed = JSON.parse(preset)
	} catch {
		return null
	}

	if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
		return null
	}

	const absolutePoints = Object.entries(waymarkStudioKeys).flatMap(
		([sourceKey, markerKey]) => {
			const marker = (parsed as Record<string, unknown>)[sourceKey]

			if (
				typeof marker !== 'object'
				|| marker === null
				|| Array.isArray(marker)
				|| (marker as WaymarkStudioMarker).Active === false
			) {
				return []
			}

			const x = finiteNumber((marker as WaymarkStudioMarker).X)
			const y = finiteNumber((marker as WaymarkStudioMarker).Z)

			return x === null || y === null
				? []
				: [{ markerKey, x, y }]
		},
	)

	if (absolutePoints.length === 0) {
		return null
	}

	const center = resolvePresetCenter(absolutePoints)
	const points = Object.fromEntries(absolutePoints.map(point => [
		point.markerKey,
		{
			x: point.x - center.x,
			y: point.y - center.y,
		},
	])) as Partial<Record<RaidPlanMarkerKey, MarkerLayoutPoint>>
	const cardinalDistances = (['A', 'B', 'C', 'D'] as RaidPlanMarkerKey[])
		.map(marker => points[marker])
		.filter((point): point is MarkerLayoutPoint => point !== undefined)
		.map(point => Math.hypot(point.x, point.y))
	const allDistances = Object.values(points).map(point => (
		Math.hypot(point.x, point.y)
	))
	const radius = Math.max(...(cardinalDistances.length > 0
		? cardinalDistances
		: allDistances))

	return radius > 0 ? { points, radius } : null
}

const resolvePresetCenter = (
	points: Array<{ markerKey: RaidPlanMarkerKey, x: number, y: number }>,
): MarkerLayoutPoint => {
	const byMarker = Object.fromEntries(points.map(point => [
		point.markerKey,
		point,
	])) as Partial<Record<RaidPlanMarkerKey, MarkerLayoutPoint>>
	const horizontalCenter = byMarker.B && byMarker.D
		? {
			x: (byMarker.B.x + byMarker.D.x) / 2,
			y: (byMarker.B.y + byMarker.D.y) / 2,
		}
		: null
	const verticalCenter = byMarker.A && byMarker.C
		? {
			x: (byMarker.A.x + byMarker.C.x) / 2,
			y: (byMarker.A.y + byMarker.C.y) / 2,
		}
		: null

	if (horizontalCenter && verticalCenter) {
		return {
			x: (horizontalCenter.x + verticalCenter.x) / 2,
			y: (horizontalCenter.y + verticalCenter.y) / 2,
		}
	}

	if (horizontalCenter || verticalCenter) {
		return horizontalCenter ?? verticalCenter!
	}

	const xs = points.map(point => point.x)
	const ys = points.map(point => point.y)

	return {
		x: (Math.min(...xs) + Math.max(...xs)) / 2,
		y: (Math.min(...ys) + Math.max(...ys)) / 2,
	}
}

export const resolveMarkerLayoutPoints = (
	layout: RaidPlanMarkerLayoutType,
	distance: number,
	waymarkPreset: string | null = null,
): Partial<Record<RaidPlanMarkerKey, MarkerLayoutPoint>> => {
	const preset = layout === 'waymark_studio'
		? waymarkPreset
		: builtInWaymarkStudioPresets[layout]
	const parsed = preset ? parseWaymarkStudioPreset(preset) : null

	if (!parsed) {
		return {}
	}

	const scale = Math.max(0, distance) / parsed.radius

	return Object.fromEntries(Object.entries(parsed.points).map(([marker, point]) => [
		marker,
		{
			x: point.x * scale,
			y: point.y * scale,
		},
	])) as Partial<Record<RaidPlanMarkerKey, MarkerLayoutPoint>>
}
