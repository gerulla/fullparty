<?php

namespace App\Services\Moderation\Targets;

use App\Models\Activity;
use App\Models\Group;
use App\Models\User;
use App\Services\Moderation\ReportTarget;
use Illuminate\Database\Eloquent\Model;

// Context targets can be reviewed and their known authors sanctioned. Hiding is
// enabled only by adapters that also enforce it at every content delivery path.
class ContextReportTarget implements ReportTarget
{
    public function __construct(private readonly string $model) {}

    public function modelClass(): string
    {
        return $this->model;
    }

    public function canHide(): bool
    {
        return false;
    }

    public function canReport(Model $target, User $reporter): bool
    {
        if ($target instanceof User) {
            return $target->public_profile || $target->id === $reporter->id;
        }
        $group = $target instanceof Group ? $target : $target->group;
        if ($group->isBanned($reporter->id)) {
            return false;
        }
        if ($target instanceof Activity && Activity::isModeratorOnlyStatus($target->status)) {
            return $group->hasModeratorAccess($reporter->id) || $target->organized_by_user_id === $reporter->id;
        }

        return $group->is_visible || $group->hasMember($reporter->id)
            || ($target instanceof Activity && $target->organized_by_user_id === $reporter->id);
    }

    public function snapshot(Model $target): array
    {
        return ['title' => $target instanceof Activity ? $target->title : $target->name,
            'text' => $target instanceof User ? $target->name : $target->description,
            'content' => $target instanceof User ? $target->only(['name', 'avatar_url'])
                : $target->only(['name', 'title', 'description'])];
    }

    public function subjectUserId(Model $target): ?int
    {
        return $target instanceof User ? $target->id : ($target instanceof Activity ? $target->organized_by_user_id : $target->owner_id);
    }
}
