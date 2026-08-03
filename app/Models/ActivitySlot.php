<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivitySlot extends Model
{
    use HasFactory;

    public const SLOT_KIND_ROSTER = 'roster';

    public const SLOT_KIND_BENCH = 'bench';

    public const SLOT_KIND_FILL_IN = 'fill_in';

    public const DESIGNATION_HOST = 'host';

    public const DESIGNATION_RAID_LEADER = 'raid_leader';

    public const DESIGNATION_COLUMN_MAP = [
        self::DESIGNATION_HOST => 'is_host',
        self::DESIGNATION_RAID_LEADER => 'is_raid_leader',
    ];

    protected $fillable = [
        'activity_id',
        'slot_kind',
        'group_key',
        'group_label',
        'filled_group_key',
        'filled_group_label',
        'slot_key',
        'slot_label',
        'position_in_group',
        'sort_order',
        'assigned_character_id',
        'assigned_by_user_id',
        'application_review_required_application_id',
        'application_review_required_at',
        'is_host',
        'is_raid_leader',
    ];

    protected $casts = [
        'group_label' => 'array',
        'filled_group_label' => 'array',
        'application_review_required_at' => 'datetime',
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

    public function applicationReviewRequiredFor(): BelongsTo
    {
        return $this->belongsTo(ActivityApplication::class, 'application_review_required_application_id');
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(ActivitySlotFieldValue::class);
    }

    public function compositionHints(): HasMany
    {
        return $this->hasMany(ActivitySlotCompositionHint::class)->orderBy('sort_order')->orderBy('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ActivitySlotAssignment::class);
    }
}
