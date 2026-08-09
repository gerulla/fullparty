<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityManagementWarning extends Model
{
    use HasFactory;

    public const TYPE_RAID_LEADER_WITHDRAWN = 'raid_leader_withdrawn';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_ERROR = 'error';

    protected $fillable = [
        'activity_id',
        'type',
        'severity',
        'payload',
        'occurred_at',
        'dismissed_by_user_id',
        'dismissed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }
}
