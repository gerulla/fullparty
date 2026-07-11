<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupFeature extends Model
{
    /**
     * @var array<string, bool>
     */
    public const DEFAULTS = [
        'availability_scheduler_enabled' => false,
        'statistics_enabled' => true,
        'leaderboard_enabled' => true,
        'calendar_sync_enabled' => false,
        'resource_hub_enabled' => false,
    ];

    protected $fillable = [
        'availability_scheduler_enabled',
        'statistics_enabled',
        'leaderboard_enabled',
        'calendar_sync_enabled',
        'resource_hub_enabled',
    ];

    protected $casts = [
        'availability_scheduler_enabled' => 'boolean',
        'statistics_enabled' => 'boolean',
        'leaderboard_enabled' => 'boolean',
        'calendar_sync_enabled' => 'boolean',
        'resource_hub_enabled' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }
}
