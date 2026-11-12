import type { GroupDashboardActivity, GroupDashboardGroup } from '@/Types/Groups'
import type { ActivityIndexItem } from '@/Types/ActivityCore'
import type { RunCardData } from '@/Types/RunCards'

export function groupRunCard(
    activity: GroupDashboardActivity | ActivityIndexItem,
    group: Pick<GroupDashboardGroup, 'name' | 'profile_picture_url'>,
): RunCardData {
    return {
        id: activity.id,
        title: activity.title,
        status: activity.status,
        starts_at: activity.starts_at,
        duration_hours: activity.duration_hours,
        href: activity.links.view,
        is_public: activity.is_public,
        participation_status: 'participation_status' in activity ? activity.participation_status : null,
        host_name: activity.organized_by?.name ?? null,
        target_prog_point_key: activity.target_prog_point_key,
        target_prog_point_label: activity.target_prog_point_label ?? undefined,
        application_count: activity.application_count,
        applicant_avatars: 'applicant_avatars' in activity ? activity.applicant_avatars : [],
        group: { name: group.name, profile_picture_url: group.profile_picture_url },
        activity_type: {
            name: activity.activity_type.draft_name,
            small_image_url: activity.small_image_url,
            banner_image_url: activity.banner_image_url,
        },
    }
}
