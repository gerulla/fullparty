<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledRun extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_UPCOMING = 'upcoming';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PLANNED,
        self::STATUS_SCHEDULED,
        self::STATUS_UPCOMING,
        self::STATUS_ONGOING,
        self::STATUS_COMPLETE,
        self::STATUS_CANCELLED,
    ];

    public const PAST_STATUSES = [
        self::STATUS_COMPLETE,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'group_id',
        'organized_by_user_id',
        'status',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organized_by_user_id');
    }
}
