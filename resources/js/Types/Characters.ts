export type CharacterIdentity = {
    id: number
    name: string
    world: string | null
    datacenter: string | null
    lodestone_id: string
    avatar_url: string | null
    is_primary: boolean
    verified_at: string | null
    claimed_at: string | null
}

export type CharacterClassProgress = {
    id: number
    name: string
    shorthand: string
    icon_url: string | null
    role: string
    level: number
    is_preferred: boolean
}

export type CharacterPhantomJobProgress = {
    id: number
    name: string
    icon_url: string | null
    black_icon_url: string | null
    current_level: number
    max_level: number
    is_preferred: boolean
    is_maxed: boolean
}

export type CharacterTowerProgress = {
    clears: number
    data_source: string
    bosses: { key: string; kills: number; progress: number }[]
}

export type AccountCharacter = CharacterIdentity & {
    add_method: string | null
    classes: CharacterClassProgress[]
    occult: {
        knowledge_level: number
        blood_progress: CharacterTowerProgress
        magic_progress: CharacterTowerProgress
        phantom_jobs: CharacterPhantomJobProgress[]
    }
}

export type CharacterManagementActions = {
    busy: boolean
    pendingAction: string | null
    error: string | null
    refresh: () => void
    makePrimary: () => void
    remove: () => Promise<void>
    toggleClass: (job: CharacterClassProgress) => void
    togglePhantomJob: (job: CharacterPhantomJobProgress) => void
}
