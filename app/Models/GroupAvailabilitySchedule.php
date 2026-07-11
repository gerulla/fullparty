<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupAvailabilitySchedule extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'cycle_weeks',
        'repeats',
        'lock_weekends',
        'on_hiatus',
        'starts_on',
        'timezone',
    ];

    protected $casts = [
        'cycle_weeks' => 'integer',
        'repeats' => 'boolean',
        'lock_weekends' => 'boolean',
        'on_hiatus' => 'boolean',
        'starts_on' => 'date',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function windows(): HasMany
    {
        return $this->hasMany(GroupAvailabilityWindow::class, 'schedule_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(GroupAvailabilityException::class, 'schedule_id');
    }
}
