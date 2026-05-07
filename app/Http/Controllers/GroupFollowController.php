<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\RedirectResponse;

class GroupFollowController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(Group $group): RedirectResponse
    {
        $user = auth()->user();

        $this->ensureUserCanInteractWithGroup($group, $user->id);

        $this->syncFollowState($group, $user->id, true);

        $this->logGroupFollowAudit($group, $user->id, 'group.followed', 'audit_log.events.group.followed');

        return back()->with('success', 'group_followed');
    }

    public function destroy(Group $group): RedirectResponse
    {
        $user = auth()->user();

        if ($group->hasMember($user->id)) {
            return back()->withErrors([
                'error' => 'group_follow_membership_locked',
            ]);
        }

        $group->followers()->detach($user->id);

        $this->logGroupFollowAudit($group, $user->id, 'group.unfollowed', 'audit_log.events.group.unfollowed');

        return back()->with('success', 'group_unfollowed');
    }

    public function updateNotifications(Group $group): RedirectResponse
    {
        $user = auth()->user();

        $this->ensureUserCanInteractWithGroup($group, $user->id);

        $validated = request()->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $existingFollow = $group->followers()
            ->where('users.id', $user->id)
            ->exists();

        if (!$existingFollow && !$group->hasMember($user->id)) {
            abort(404);
        }

        $notificationsEnabled = (bool) $validated['enabled'];

        $this->syncFollowState($group, $user->id, $notificationsEnabled);

        $this->logGroupFollowAudit(
            $group,
            $user->id,
            $notificationsEnabled ? 'group.notifications.enabled' : 'group.notifications.muted',
            $notificationsEnabled ? 'audit_log.events.group.notifications.enabled' : 'audit_log.events.group.notifications.muted',
            [
                'notifications_enabled' => $notificationsEnabled,
            ],
        );

        return back()->with('success', $notificationsEnabled
            ? 'group_notifications_enabled'
            : 'group_notifications_muted');
    }

    private function ensureUserCanInteractWithGroup(Group $group, int $userId): void
    {
        if ($group->isBanned($userId)) {
            abort(403);
        }

        if (!$group->is_public && !$group->hasMember($userId)) {
            abort(404);
        }
    }

    private function syncFollowState(Group $group, int $userId, bool $notificationsEnabled): void
    {
        $group->followers()->syncWithoutDetaching([
            $userId => ['notifications_enabled' => $notificationsEnabled],
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function logGroupFollowAudit(
        Group $group,
        int $userId,
        string $action,
        string $message,
        array $metadata = [],
    ): void {
        $user = auth()->user();

        $this->auditLogger->log(
            action: $action,
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: $message,
            actor: $user,
            subject: $group,
            metadata: array_merge([
                'user_id' => $userId,
            ], $metadata),
        );
    }
}
