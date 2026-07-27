<?php

namespace App\Models;

use Database\Factories\RaidPlanMechanicFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RaidPlanMechanic extends Model
{
    /** @use HasFactory<RaidPlanMechanicFactory> */
    use HasFactory;

    public const TYPE_FIXED = 'fixed';

    public const TYPE_RANDOM_SET = 'random_set';

    public const TYPES = [
        self::TYPE_FIXED,
        self::TYPE_RANDOM_SET,
    ];

    public const CURRENT_TIMELINE_SCHEMA_VERSION = 1;

    protected $fillable = [
        'raid_plan_id',
        'parent_id',
        'name',
        'type',
        'sort_order',
        'duration_ms',
        'selection_weight',
        'is_enabled',
        'timeline',
        'timeline_schema_version',
    ];

    protected $attributes = [
        'type' => self::TYPE_FIXED,
        'sort_order' => 0,
        'duration_ms' => 0,
        'selection_weight' => 1,
        'is_enabled' => true,
        'timeline' => '{}',
        'timeline_schema_version' => self::CURRENT_TIMELINE_SCHEMA_VERSION,
    ];

    protected function casts(): array
    {
        return [
            'raid_plan_id' => 'integer',
            'parent_id' => 'integer',
            'sort_order' => 'integer',
            'duration_ms' => 'integer',
            'selection_weight' => 'integer',
            'is_enabled' => 'boolean',
            'timeline' => 'array',
            'timeline_schema_version' => 'integer',
        ];
    }

    public function raidPlan(): BelongsTo
    {
        return $this->belongsTo(RaidPlan::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function isFixed(): bool
    {
        return $this->type === self::TYPE_FIXED;
    }

    public function isRandomSet(): bool
    {
        return $this->type === self::TYPE_RANDOM_SET;
    }
}
