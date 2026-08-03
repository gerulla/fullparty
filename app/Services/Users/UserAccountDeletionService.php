<?php

namespace App\Services\Users;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Notifications\NotificationService;
use App\Support\Activities\ActivityDisplayName;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserAccountDeletionService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ActivitySlotAttendanceService $attendanceService,
        private readonly NotificationService $notificationService,
    ) {}

    public function delete(User $user): void
    {
        $this->ensureUserCanDeleteAccount($user);

        $originalEmail = $user->email;
        $deletedUserName = $user->name;
        $upcomingAssignmentNotifications = [];

        DB::transaction(function () use ($user, $originalEmail, $deletedUserName, &$upcomingAssignmentNotifications): void {
            $this->auditLogger->log(
                action: 'user.account.deleted',
                severity: AuditSeverity::SEVERE_CHANGE,
                scopeType: AuditScope::USER,
                scopeId: $user->id,
                message: 'audit_log.events.user.account.deleted',
                actor: $user,
                subject: $user,
            );

            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();

            if (filled($originalEmail)) {
                DB::table('password_reset_tokens')
                    ->where('email', $originalEmail)
                    ->delete();
            }

            $upcomingAssignmentNotifications = $this->clearUpcomingRunState($user, $deletedUserName);

            $this->releaseCharacters($user);

            DB::table('group_bans')
                ->where('user_id', $user->id)
                ->delete();

            DB::table('system_notification_broadcast_reads')
                ->where('user_id', $user->id)
                ->delete();

            $user->receivedGroupNotes()->delete();
            $user->groupMemberships()->delete();
            $user->socialAccounts()->delete();
            $user->discordUserIntegration()->delete();
            $user->inAppNotifications()->delete();
            $user->notificationDeliveries()->delete();

            $user->forceFill([
                'name' => sprintf('Deleted User #%d', $user->id),
                'email' => sprintf('deleted-user-%d-%s@deleted.fullparty.local', $user->id, Str::lower(Str::random(12))),
                'password' => Hash::make(Str::random(64)),
                'email_verified_at' => null,
                'avatar_url' => null,
                'is_admin' => false,
                'public_profile' => false,
                'public_characters' => false,
                'application_notifications' => false,
                'run_and_reminder_notifications' => false,
                'group_update_notifications' => false,
                'assignment_notifications' => false,
                'account_character_notifications' => false,
                'system_notice_notifications' => false,
                'email_notifications' => false,
                'discord_notifications' => false,
                'remember_token' => null,
            ])->save();
        });

        $this->notifyModeratorsAboutClearedUpcomingAssignments($upcomingAssignmentNotifications);
    }

    private function ensureUserCanDeleteAccount(User $user): void
    {
        if ($user->ownedGroups()->exists()) {
            throw ValidationException::withMessages([
                'error' => 'account_delete_group_owner',
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function clearUpcomingRunState(User $user, string $deletedUserName): array
    {
        $characterIds = Character::query()
            ->where('user_id', $user->id)
            ->pluck('id');

        $this->withdrawActiveApplications($user);

        if ($characterIds->isEmpty()) {
            return [];
        }

        $upcomingSlots = ActivitySlot::query()
            ->with(['activity.group', 'activity.activityTypeVersion', 'activity.activityType', 'fieldValues'])
            ->whereIn('assigned_character_id', $characterIds)
            ->whereHas('activity', function ($query): void {
                $query
                    ->where('starts_at', '>=', now())
                    ->whereNotIn('status', Activity::ARCHIVED_STATUSES);
            })
            ->get();

        $notifications = $this->buildUpcomingAssignmentNotifications($upcomingSlots, $user, $deletedUserName);

        foreach ($upcomingSlots as $slot) {
            $activity = $slot->activity;
            $characterId = $slot->assigned_character_id ? (int) $slot->assigned_character_id : null;

            $slot->forceFill([
                'assigned_character_id' => null,
                'assigned_by_user_id' => null,
            ])->save();

            foreach ($slot->fieldValues as $fieldValue) {
                $fieldValue->forceFill([
                    'value' => null,
                ])->save();
            }

            if ($activity instanceof Activity && $characterId !== null) {
                $this->attendanceService->endActiveAssignment($activity, $characterId);
            }
        }

        return $notifications;
    }

    private function withdrawActiveApplications(User $user): void
    {
        ActivityApplication::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)
            ->whereHas('activity', fn ($query) => $query->whereNotIn('status', Activity::ARCHIVED_STATUSES))
            ->update([
                'status' => ActivityApplication::STATUS_WITHDRAWN,
                'guest_access_token' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => now(),
                'review_reason' => null,
            ]);
    }

    private function releaseCharacters(User $user): void
    {
        Character::query()
            ->where('user_id', $user->id)
            ->update([
                'user_id' => null,
                'is_primary' => false,
                'verified_at' => null,
                'token' => null,
                'expires_at' => null,
            ]);
    }

    /**
     * @param  EloquentCollection<int, ActivitySlot>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function buildUpcomingAssignmentNotifications(EloquentCollection $slots, User $user, string $deletedUserName): array
    {
        return $slots
            ->filter(fn (ActivitySlot $slot): bool => $slot->activity instanceof Activity && $slot->activity->group instanceof Group)
            ->groupBy(fn (ActivitySlot $slot): int => (int) $slot->activity->group_id)
            ->map(function ($groupSlots) use ($user, $deletedUserName): array {
                /** @var ActivitySlot $firstSlot */
                $firstSlot = $groupSlots->first();
                $group = $firstSlot->activity->group;
                $activities = $groupSlots
                    ->pluck('activity')
                    ->unique('id')
                    ->values();

                return [
                    'group_id' => (int) $group->id,
                    'group_slug' => $group->slug,
                    'group_name' => $group->name,
                    'user_id' => (int) $user->id,
                    'user_name' => $deletedUserName,
                    'runs' => $activities
                        ->map(fn (Activity $activity): array => [
                            'id' => (int) $activity->id,
                            'title' => ActivityDisplayName::for($activity),
                            'starts_at' => $activity->starts_at?->toISOString(),
                        ])
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $notifications
     */
    private function notifyModeratorsAboutClearedUpcomingAssignments(array $notifications): void
    {
        foreach ($notifications as $notification) {
            $group = Group::query()->find($notification['group_id']);

            if (! $group instanceof Group) {
                continue;
            }

            $recipients = $this->groupModeratorRecipients($group, (int) $notification['user_id']);

            if ($recipients->isEmpty()) {
                continue;
            }

            $runTitles = collect($notification['runs'])
                ->pluck('title')
                ->filter()
                ->implode(', ');

            $event = $this->notificationService->createEvent(
                type: 'groups.member_upcoming_assignments_cleared',
                category: NotificationCategory::GROUP_UPDATES,
                titleKey: 'notifications.groups.member_upcoming_assignments_cleared.title',
                bodyKey: 'notifications.groups.member_upcoming_assignments_cleared.body',
                messageParams: [
                    'group' => $group->name,
                    'user' => $notification['user_name'],
                    'runs' => $runTitles,
                ],
                actionUrl: route('groups.dashboard.activities.index', $group),
                subject: $group,
                payload: [
                    'group_id' => (int) $group->id,
                    'group_slug' => $group->slug,
                    'user_id' => (int) $notification['user_id'],
                    'user_name' => $notification['user_name'],
                    'runs' => $notification['runs'],
                ],
                topic: NotificationTopic::GROUP_MEMBERSHIP,
                groupId: (int) $group->id,
            );

            $this->notificationService->sendInAppNotifications($event, $recipients);
        }
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function groupModeratorRecipients(Group $group, int $deletedUserId): EloquentCollection
    {
        $recipientIds = $group->memberships()
            ->whereIn('role', [
                GroupMembership::ROLE_OWNER,
                GroupMembership::ROLE_ADMIN,
                GroupMembership::ROLE_MODERATOR,
            ])
            ->pluck('user_id')
            ->push($group->owner_id)
            ->filter(fn ($userId): bool => (int) $userId !== $deletedUserId)
            ->unique()
            ->values();

        return User::query()
            ->whereKey($recipientIds)
            ->get();
    }
}
