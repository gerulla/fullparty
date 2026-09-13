<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModerationCase extends Model
{
    public const STATUSES = ['new', 'in_review', 'awaiting_feedback', 'resolved'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'resolved_at' => 'datetime'];
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ContentReport::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ModerationAction::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(ReportFeedback::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}
