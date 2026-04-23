<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'activity_id',
        'user_id',
        'selected_character_id',
        'status',
        'notes',
        'reviewed_by_user_id',
        'submitted_at',
        'reviewed_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function selectedCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'selected_character_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ActivityApplicationAnswer::class);
    }
}
