<?php

namespace App\Services\Moderation\Targets;

use App\Models\BozjaHolster;
use App\Models\GroupResource;
use App\Models\User;
use App\Services\Moderation\ReportTarget;
use App\Services\RichText\RichTextDocument;
use Illuminate\Database\Eloquent\Model;

class HolsterReportTarget implements ReportTarget
{
    public function __construct(private readonly ResourceReportTarget $resources) {}

    public function modelClass(): string
    {
        return BozjaHolster::class;
    }

    public function canReport(Model $target, User $reporter): bool
    {
        if ($target->moderation_hidden_at || ! $target->is_active || $target->group->isBanned($reporter->id)) {
            return false;
        }
        if ($target->group->hasMember($reporter->id)) {
            return true;
        }
        $resource = GroupResource::where('group_id', $target->group_id)->where('holster_id', $target->id)->first();

        return $resource && $this->resources->canReport($resource, $reporter);
    }

    public function snapshot(Model $target): array
    {
        return ['title' => $target->name['en'] ?? (string) $target->id,
            'text' => trim(($target->notes ?? '')."\n\n".(is_array($target->guide) ? app(RichTextDocument::class)->text($target->guide) : ($target->guide ?? ''))),
            'content' => $target->only(['name', 'notes', 'guide', 'type', 'role', 'parent_holster_id']),
            'group' => $target->group->name];
    }

    public function subjectUserId(Model $target): ?int
    {
        return null;
    }

    public function canHide(): bool
    {
        return true;
    }
}
