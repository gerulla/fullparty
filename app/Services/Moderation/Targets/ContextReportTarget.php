<?php

namespace App\Services\Moderation\Targets;

use App\Models\Activity;
use App\Models\User;
use App\Services\Moderation\ReportTarget;
use App\Services\Runs\RunAccessService;
use Illuminate\Database\Eloquent\Model;

// Context targets can be reviewed and their known authors sanctioned. Hiding is
// enabled only by adapters that also enforce it at every content delivery path.
class ContextReportTarget implements ReportTarget
{
    public function __construct(
        private readonly string $model,
        private readonly RunAccessService $access,
    ) {}

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
        if ($target instanceof Activity) {
            return $this->access->canView($target->group, $target, $reporter->id);
        }
        $group = $target;
        if ($group->isBanned($reporter->id)) {
            return false;
        }

        return $group->is_visible || $group->hasMember($reporter->id);
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
