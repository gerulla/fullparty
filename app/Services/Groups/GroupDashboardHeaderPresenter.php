<?php

namespace App\Services\Groups;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceSettingsService;

class GroupDashboardHeaderPresenter
{
    public function __construct(
        private readonly NotificationPreferenceSettingsService $notificationPreferences,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(Group $group, ?User $user): array
    {
        $group->loadMissing(['memberships', 'features', 'availabilitySettings']);
        $userId = $user?->id;
        $membership = $userId === null ? null : $group->memberships->firstWhere('user_id', $userId);
        $isMember = $group->hasMember($userId);
        $isOwner = $group->isOwnedBy($userId);
        $canModerate = $isMember && $group->hasModeratorAccess($userId);
        $canAdminister = $isMember && $group->hasAdminAccess($userId);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'group_type' => $group->group_type,
            'profile_picture_url' => $group->profile_picture_url,
            'banner_image_url' => $group->banner_image_url,
            'discord_invite_url' => $group->discord_invite_url,
            'is_visible' => $group->is_visible,
            'current_user_role' => $isOwner ? GroupMembership::ROLE_OWNER : $membership?->role,
            'features' => $group->featureSettings(),
            'notifications' => [
                'enabled' => $membership instanceof GroupMembership
                    ? (bool) ($membership->notifications_enabled ?? true)
                    : false,
                'preferences' => $membership instanceof GroupMembership
                    ? $this->notificationPreferences->serializeGroupPreferences($user, $group->id)
                    : [],
            ],
            'permissions' => [
                'can_manage_group' => $isOwner,
                'can_update_group_settings' => $canAdminister,
                'can_manage_members' => $canModerate,
                'can_manage_discovery' => $canAdminister,
                'can_manage_activities' => $canModerate,
                'can_view_members' => $isMember,
                'can_review_membership_applications' => $group->usesMembershipApplications() && $canModerate,
                'can_manage_membership_application_form' => $group->usesMembershipApplications() && $canAdminister,
                'can_leave' => $membership instanceof GroupMembership && ! $isOwner,
                'can_toggle_notifications' => $membership instanceof GroupMembership,
            ],
        ];
    }
}
