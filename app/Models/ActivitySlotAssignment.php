<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivitySlotAssignment extends Model
{
    use HasFactory;

    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_LATE = 'late';
    public const STATUS_MISSING = 'missing';

    public const STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_CHECKED_IN,
        self::STATUS_LATE,
        self::STATUS_MISSING,
    ];

    protected $fillable = [
        'activity_id',
        'group_id',
        'activity_slot_id',
        'character_id',
        'application_id',
        'field_values_snapshot',
        'attendance_status',
        'assigned_at',
        'assigned_by_user_id',
        'checked_in_at',
        'checked_in_by_user_id',
        'marked_missing_at',
        'marked_missing_by_user_id',
        'ended_at',
    ];

    protected $casts = [
        'field_values_snapshot' => 'array',
        'assigned_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'marked_missing_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ActivitySlot::class, 'activity_slot_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ActivityApplication::class, 'application_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by_user_id');
    }

    public function markedMissingBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_missing_by_user_id');
    }
}
