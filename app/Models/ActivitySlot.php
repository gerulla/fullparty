<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivitySlot extends Model
{
    use HasFactory;

    public const DESIGNATION_HOST = 'host';
    public const DESIGNATION_RAID_LEADER = 'raid_leader';

    public const DESIGNATION_COLUMN_MAP = [
        self::DESIGNATION_HOST => 'is_host',
        self::DESIGNATION_RAID_LEADER => 'is_raid_leader',
    ];

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
        'is_host',
        'is_raid_leader',
    ];

    protected $casts = [
        'group_label' => 'array',
        'slot_label' => 'array',
        'is_host' => 'boolean',
        'is_raid_leader' => 'boolean',
    ];

    public static function designationColumn(string $designation): string
    {
        return self::DESIGNATION_COLUMN_MAP[$designation]
            ?? throw new \InvalidArgumentException("Unsupported slot designation [{$designation}].");
    }

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
