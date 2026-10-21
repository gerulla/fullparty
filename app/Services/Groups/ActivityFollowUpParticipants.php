<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityGroupFollowUp;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

final class ActivityFollowUpParticipants
{
    /**
     * Capture the participating account at completion. Later character transfers
     * must not redirect this prompt or invitation to the character's new owner.
     */
    public function attendees(Activity $activity): Collection
    {
        return $this->assignments($activity)
            ->filter(fn (ActivitySlotAssignment $assignment) => $this->attended($assignment))
            ->map(function (ActivitySlotAssignment $assignment): ?array {
                $user = $assignment->application_id !== null
                    ? $assignment->application?->user
                    : $assignment->character?->user;
                if (! $user || $user->banned_at !== null) {
                    return null;
                }

                return ['user' => $user, 'assignment' => $assignment];
            })
            ->filter()
            ->groupBy(fn (array $entry) => $entry['user']->id)
            ->map(fn (Collection $entries) => [
                'user' => $entries->first()['user'],
                'assignment_ids' => $entries->pluck('assignment.id')->all(),
                'characters' => $entries->map(fn (array $entry) => [
                    'id' => $entry['assignment']->character_id,
                    'name' => $entry['assignment']->character?->name,
                    'avatar_url' => $entry['assignment']->character?->avatar_url,
                ])->unique('id')->values()->all(),
            ]);
    }

    public function eligible(Activity $activity, string $kind, ?int $userId = null): Collection
    {
        if ($activity->status !== Activity::STATUS_COMPLETE) {
            return collect();
        }

        $entries = ActivityGroupFollowUp::query()
            ->with('user')
            ->where('activity_id', $activity->id)->where('kind', $kind)
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))->get();
        if ($entries->isEmpty()) {
            return $entries;
        }
        $group = $activity->group()->with(['memberships', 'bans'])->firstOrFail();
        $characterIds = $entries->flatMap(fn ($entry) => collect($entry->characters)->pluck('id'))->unique()->all();
        $attendedIds = $this->assignments($activity, $characterIds)->filter(fn ($assignment) => $this->attended($assignment))->pluck('id');

        return $entries
            ->filter(fn (ActivityGroupFollowUp $entry) => $entry->user
                && $this->canJoin($group, $entry->user)
                && $attendedIds->intersect($entry->assignment_ids ?? [])->isNotEmpty())
            ->keyBy('user_id');
    }

    public function canJoin(Group $group, User $user): bool
    {
        return $user->banned_at === null && ! $group->isBanned($user->id) && ! $group->hasMember($user->id);
    }

    private function assignments(Activity $activity, ?array $characterIds = null): Collection
    {
        return $activity->slotAssignments()->with(['slot', 'application.user', 'character.user'])
            ->when($characterIds !== null, fn ($query) => $query->whereIn('character_id', $characterIds))
            ->orderByDesc('assigned_at')->orderByDesc('id')->get()->unique('character_id');
    }

    private function attended(ActivitySlotAssignment $assignment): bool
    {
        return in_array($assignment->attendance_status, [ActivitySlotAssignment::STATUS_CHECKED_IN, ActivitySlotAssignment::STATUS_LATE], true)
            && $assignment->slot !== null && $assignment->slot->slot_kind !== ActivitySlot::SLOT_KIND_BENCH
            && ($assignment->application_id === null || ($assignment->application?->user_id !== null
                && ! in_array($assignment->application->status, [ActivityApplication::STATUS_WITHDRAWN, ActivityApplication::STATUS_DECLINED, ActivityApplication::STATUS_CANCELLED], true)));
    }
}
