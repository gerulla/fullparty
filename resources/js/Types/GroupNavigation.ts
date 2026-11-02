import type { GroupDashboardGroup, GroupFeatureSettings } from '@/Types/Groups'

export type GroupNavigationContext = Pick<GroupDashboardGroup, 'slug'> &
    Partial<Pick<GroupDashboardGroup, 'name' | 'profile_picture_url' | 'current_user_role'>> & {
        permissions?: Partial<GroupDashboardGroup['permissions']>
        features?: Partial<GroupFeatureSettings>
    }

export type GroupNavigationLink = {
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
