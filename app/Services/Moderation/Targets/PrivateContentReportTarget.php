<?php

namespace App\Services\Moderation\Targets;

use App\Models\ActivityApplication;
use App\Models\GroupUserNote;
use App\Models\User;
use App\Services\Groups\GroupUserNoteVisibilityService;
use App\Services\Moderation\ReportTarget;
use Illuminate\Database\Eloquent\Model;

class PrivateContentReportTarget implements ReportTarget
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
        if ($target instanceof GroupUserNote) {
            return app(GroupUserNoteVisibilityService::class)->canViewNote($target, $reporter);
        }
        $group = $target instanceof ActivityApplication ? $target->activity->group : $target->group;
        if ($group->isBanned($reporter->id)) {
            return false;
        }

        return $group->hasModeratorAccess($reporter->id)
            || (! $target instanceof GroupUserNote && $target->user_id === $reporter->id);
    }

    public function snapshot(Model $target): array
    {
        if ($target instanceof GroupUserNote) {
            return ['title' => __('reports.types.member_note').' #'.$target->id, 'content' => $target->only(['body', 'severity']) + [
                'addenda' => $target->addenda->map->only(['body', 'author_user_id'])->all(),
            ]];
        }

        return ['title' => __('reports.types.application').' #'.$target->id,
            'content' => $target instanceof ActivityApplication
                ? ['notes' => $target->notes, 'answers' => $target->answers->map->only(['question_label', 'value'])->all()]
                : ['answers' => $target->answers]];
    }

    public function subjectUserId(Model $target): ?int
    {
        return $target instanceof GroupUserNote ? $target->author_user_id : $target->user_id;
    }
}
