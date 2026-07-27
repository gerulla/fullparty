import marker1Url from '../assets/markers/marker-1.png'
import marker2Url from '../assets/markers/marker-2.png'
import marker3Url from '../assets/markers/marker-3.png'
import marker4Url from '../assets/markers/marker-4.png'
import markerAUrl from '../assets/markers/marker-a.png'
import markerBUrl from '../assets/markers/marker-b.png'
import markerCUrl from '../assets/markers/marker-c.png'
import markerDUrl from '../assets/markers/marker-d.png'
import type { RaidPlanMarkerKey } from '../types/RaidPlan'

export const raidPlanMarkerAssets: Record<RaidPlanMarkerKey, string> = {
	'1': marker1Url,
	'2': marker2Url,
	'3': marker3Url,
	'4': marker4Url,
	A: markerAUrl,
	B: markerBUrl,
	C: markerCUrl,
	D: markerDUrl,
}

export const raidPlanMarkerKeys = Object.keys(
	raidPlanMarkerAssets,
) as RaidPlanMarkerKey[]
