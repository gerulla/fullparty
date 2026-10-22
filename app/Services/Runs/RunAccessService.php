<?php

namespace App\Services\Runs;

use App\Models\Activity;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Participant run access, independent of group discovery and join mode.
 * Management permissions, capacity, character eligibility and token ownership
 * remain with their respective policies and workflows.
 */
final class RunAccessService
{
    /**
     * Notifications run outside HTTP middleware and may start from stale relations.
     * Recheck both account bans and run access before disclosing run details.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    public function filterViewers(Activity $activity, Collection $users): Collection
    {
        if ($users->isEmpty()) {
            return $users;
        }

        $group = $activity->group()->with(['memberships', 'bans'])->firstOrFail();

        return User::query()->whereKey($users->modelKeys())->whereNull('banned_at')->get()
            ->filter(fn (User $user): bool => $this->canView($group, $activity, $user->id))->values();
    }

    public function canView(Group $group, Activity $activity, ?int $userId): bool
    {
        if ((int) $activity->group_id !== (int) $group->id || $group->isBanned($userId)) {
            return false;
        }

        $organizer = $userId !== null && (int) $activity->organized_by_user_id === $userId;
        if (Activity::isModeratorOnlyStatus($activity->status)) {
            return $organizer || $group->hasModeratorAccess($userId);
        }

        return $activity->is_public || $organizer || $group->hasMember($userId);
    }

    public function canParticipate(Group $group, Activity $activity, ?int $userId): bool
    {
        return $this->canView($group, $activity, $userId)
            && ($userId !== null || $activity->allow_guest_applications);
    }

    public function canApply(Group $group, Activity $activity, ?int $userId): bool
    {
        return $activity->needs_application && $activity->acceptsApplications()
            && $this->canParticipate($group, $activity, $userId);
    }

    public function canSelfAssign(Group $group, Activity $activity, ?int $userId): bool
    {
        return ! $activity->needs_application && ! $activity->isArchived()
            && $this->canParticipate($group, $activity, $userId);
    }

    /**
     * Available participant actions shared by overview and application responses.
     *
     * @return array{can_apply: bool, can_apply_as_guest: bool, can_self_assign: bool, can_self_assign_as_guest: bool, requires_login: bool}
     */
    public function participationPermissions(Group $group, Activity $activity, ?int $userId): array
    {
        $canApplyAsGuest = $userId === null && $this->canApply($group, $activity, null);
        $canSelfAssignAsGuest = $userId === null && $this->canSelfAssign($group, $activity, null);
        $joiningOpen = $activity->needs_application ? $activity->acceptsApplications() : ! $activity->isArchived();

        return [
            'can_apply' => $userId !== null && $this->canApply($group, $activity, $userId),
            'can_apply_as_guest' => $canApplyAsGuest,
            'can_self_assign' => $userId !== null && $this->canSelfAssign($group, $activity, $userId),
            'can_self_assign_as_guest' => $canSelfAssignAsGuest,
            'requires_login' => $userId === null && $joiningOpen && ! $canApplyAsGuest && ! $canSelfAssignAsGuest
                && $this->canView($group, $activity, null),
        ];
    }

    /** Existing forms may resend these settings, but cannot change them after creation. */
    public function validateUnchangedSettings(Activity $activity, array $input): void
    {
        $errors = [];
        foreach (['is_public', 'allow_guest_applications'] as $field) {
            if (array_key_exists($field, $input) && (bool) $input[$field] !== (bool) $activity->{$field}) {
                $errors[$field] = __('errors.run_access_settings_locked');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Apply before limits/pagination so inaccessible runs cannot consume results.
     * Keep this SQL equivalent to canView; the access matrix tests cover both.
     * Read-only member integrations can explicitly exclude all drafts.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeVisibleTo(Builder $query, ?int $userId, bool $includeDrafts = true): Builder
    {
        return $query
            ->whereHas('group', fn (Builder $groups) => $groups
                ->when($userId !== null, fn (Builder $groups) => $groups
                    ->whereDoesntHave('bans', fn (Builder $bans) => $bans->where('user_id', $userId))))
            ->where(function (Builder $runs) use ($userId, $includeDrafts): void {
                $runs->where(function (Builder $published) use ($userId): void {
                    $published->whereNotIn('activities.status', Activity::MODERATOR_ONLY_STATUSES)
                        ->where(function (Builder $audience) use ($userId): void {
                            $audience->where('activities.is_public', true);
                            if ($userId !== null) {
                                $audience->orWhere('activities.organized_by_user_id', $userId)
                                    ->orWhereHas('group', fn (Builder $groups) => $this->scopeGroupMember($groups, $userId));
                            }
                        });
                });

                if ($includeDrafts && $userId !== null) {
                    $runs->orWhere(function (Builder $drafts) use ($userId): void {
                        $drafts->whereIn('activities.status', Activity::MODERATOR_ONLY_STATUSES)
                            ->where(fn (Builder $audience) => $audience
                                ->where('activities.organized_by_user_id', $userId)
                                ->orWhereHas('group', fn (Builder $groups) => $this->scopeGroupMember($groups, $userId, moderatorsOnly: true)));
                    });
                }
            });
    }

    private function scopeGroupMember(Builder $query, int $userId, bool $moderatorsOnly = false): Builder
    {
        return $query->where(fn (Builder $groups) => $groups->where('owner_id', $userId)
            ->orWhereHas('memberships', fn (Builder $members) => $members->where('user_id', $userId)
                ->when($moderatorsOnly, fn (Builder $members) => $members
                    ->whereIn('role', [GroupMembership::ROLE_ADMIN, GroupMembership::ROLE_MODERATOR]))));
    }
}
