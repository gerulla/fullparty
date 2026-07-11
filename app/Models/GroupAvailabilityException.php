<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupAvailabilityException extends Model
{
    public const STATUS_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'schedule_id',
        'date',
        'status',
        'starts_minute',
        'ends_minute',
    ];

    protected $casts = [
        'date' => 'date',
        'starts_minute' => 'integer',
        'ends_minute' => 'integer',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(GroupAvailabilitySchedule::class, 'schedule_id');
    }
}
