<?php

namespace App\Services\Moderation\Targets;

use App\Models\GroupResourceImage;
use App\Models\User;
use App\Services\Groups\Resources\ResourceImageService;
use App\Services\Moderation\ReportTarget;
use Illuminate\Database\Eloquent\Model;

class UploadReportTarget implements ReportTarget
{
    public function __construct(private readonly ResourceImageService $images) {}

    public function modelClass(): string
    {
        return GroupResourceImage::class;
    }

    public function canReport(Model $target, User $reporter): bool
    {
        return ! $target->moderation_hidden_at
            && ($this->images->canRead($target, $reporter, false) || $this->images->canRead($target, $reporter, true));
    }

    public function snapshot(Model $target): array
    {
        return ['title' => $target->original_name, 'content' => $target->only(['uuid', 'alt_text', 'caption', 'mime_type', 'width', 'height'])];
    }

    public function subjectUserId(Model $target): ?int
    {
        return $target->uploader_user_id;
    }

    public function canHide(): bool
    {
        return true;
    }
}
