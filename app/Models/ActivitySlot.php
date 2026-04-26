<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivitySlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'activity_id',
        'group_key',
        'group_label',
        'slot_key',
        'slot_label',
        'position_in_group',
        'sort_order',
        'assigned_character_id',
        'assigned_by_user_id',
    ];

    protected $casts = [
        'group_label' => 'array',
        'slot_label' => 'array',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function assignedCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'assigned_character_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(ActivitySlotFieldValue::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ActivitySlotAssignment::class);
    }
}
