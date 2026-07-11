<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupAvailabilityWindow extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_TENTATIVE = 'tentative';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_TENTATIVE,
    ];

    protected $fillable = [
        'schedule_id',
        'cycle_week',
        'weekday',
        'status',
        'starts_minute',
        'ends_minute',
    ];

    protected $casts = [
        'cycle_week' => 'integer',
        'weekday' => 'integer',
        'starts_minute' => 'integer',
        'ends_minute' => 'integer',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(GroupAvailabilitySchedule::class, 'schedule_id');
    }
}
