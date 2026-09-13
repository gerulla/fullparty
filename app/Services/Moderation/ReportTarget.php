<?php

namespace App\Services\Moderation;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

interface ReportTarget
{
    public function modelClass(): string;

    public function canReport(Model $target, User $reporter): bool;

    public function snapshot(Model $target): array;

    public function subjectUserId(Model $target): ?int;

    public function canHide(): bool;
}
