import type { PhantomJobOption } from '@/Types/PhantomComposition'

export type ForkedTowerWorkspaceData = {
    phantom_jobs: PhantomJobOption[]
}

export type GroupContentSummary = {
    holsters: { total: number; active: number }
    compositions: { total: number; active: number }
}
