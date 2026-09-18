export type GameIconCategory = 'class' | 'phantom_job' | 'role' | 'class_action' | 'role_action' | 'phantom_action' | 'bozja_action' | 'bozja_item'

export type GameIcon = {
    key: string
    shortcode: string
    category: GameIconCategory
    src: string
    names: Record<string, string>
    aliases: string[]
    job: string
}

export type GameIconAttributes = Pick<GameIcon, 'key' | 'shortcode' | 'src'>
