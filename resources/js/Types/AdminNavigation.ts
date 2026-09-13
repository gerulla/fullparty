export type AdminNavigationLink = {
    label: string
    icon: string
    to: string
    active: boolean
}

export type AdminNavigationSection = {
    label: string
    icon: string
    links: AdminNavigationLink[]
}
