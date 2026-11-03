import type { GroupDashboardGroup, GroupFeatureSettings, GroupRole } from '@/Types/Groups'

export type GroupQuickLink = {
    id: number
    name: string
    slug: string
    href: string
    profile_picture_url: string | null
    role: GroupRole | null
}

export type GroupNavigationSharedData = {
    group_quick_links?: {
        my: GroupQuickLink[]
        joined: GroupQuickLink[]
    }
}

export type GroupNavigationContext = Pick<GroupDashboardGroup, 'slug'> &
    Partial<Pick<GroupDashboardGroup, 'name' | 'profile_picture_url' | 'current_user_role'>> & {
        permissions?: Partial<GroupDashboardGroup['permissions']>
        features?: Partial<GroupFeatureSettings>
    }

export type GroupNavigationLink = {
    key: string
    label: string
    icon: string
    href: string
    active: boolean
}

export type GroupNavigationSection = {
    key: string
    label: string
    links: GroupNavigationLink[]
}

export type GroupHomeNavigationLink = GroupNavigationLink & {
    description: string
    badge?: string
}

export type GroupHomeNavigationItem = {
    key: string
    label: string
    icon: string
    active: boolean
    href?: string
    links?: GroupHomeNavigationLink[]
}

export type GroupHomeQuickLinkSection = {
    key: string
    label: string
    icon: string
    links: GroupHomeNavigationLink[]
}
