<?php

namespace App\Services\Moderation\Targets;

use App\Models\GroupResource;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use App\Services\Groups\Resources\ResourceHolsterContent;
use App\Services\Groups\Resources\ResourceLibraryService;
use App\Services\Moderation\ReportTarget;
use App\Services\RichText\RichTextDocument;
use Illuminate\Database\Eloquent\Model;

class ResourceReportTarget implements ReportTarget
{
    public function __construct(private readonly GroupResourcePolicy $policy, private readonly ResourceLibraryService $libraries, private readonly ResourceHolsterContent $holsters) {}

    public function modelClass(): string
    {
        return GroupResource::class;
    }

    public function canReport(Model $target, User $reporter): bool
    {
        return ! $target->moderation_hidden_at && ! $target->group->isBanned($reporter->id)
            && GroupResource::whereKey($target->id)->withAvailableSource()->exists()
            && ($this->policy->view($reporter, $target) || $this->libraries->publicUrl($target) !== null);
    }

    public function snapshot(Model $target): array
    {
        $snapshot = $target->publishedRevision?->snapshot ?? [];
        if ($target->holster) {
            $snapshot = $this->holsters->inherit($target, $snapshot);
        }
        $body = $snapshot['body_text'] ?? app(RichTextDocument::class)->text($snapshot['body'] ?? ['type' => 'doc', 'content' => []]);

        return ['title' => $snapshot['title'] ?? $target->slug, 'text' => trim(($snapshot['description'] ?? '')."\n\n".$body), 'content' => $snapshot, 'group' => $target->group->name];
    }

    public function subjectUserId(Model $target): ?int
    {
        // Holsters have no author record; do not guess that the group owner wrote them.
        return $target->holster_id ? null : $target->publishedRevision?->editor_user_id;
    }

    public function canHide(): bool
    {
        return true;
    }
}
