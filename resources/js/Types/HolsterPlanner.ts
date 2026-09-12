export type HolsterLoadoutItem = {
    id: string | number
    name: string
    icon_url: string | null
    quantity: number
    cache_weight?: number
}

export type HolsterLoadout = {
    id: string | number
    name: string
    role: string | null
    type: 'prepop' | 'refill'
    notes: string | null
    capacity_used: number | null
    max_capacity: number | null
    items: HolsterLoadoutItem[]
}

export type HolsterPlannerGroup = { prepop: HolsterLoadout; refills: HolsterLoadout[]; standalone: boolean }
