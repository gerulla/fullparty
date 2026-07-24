<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaidPlanAccessLink extends Model
{
    public const PERMISSION_VIEW = 'view';

    public const PERMISSION_EDIT = 'edit';

    public const PERMISSIONS = [
        self::PERMISSION_VIEW,
        self::PERMISSION_EDIT,
    ];

    protected $fillable = [
        'raid_plan_id',
        'permission',
        'token',
        'token_hash',
        'rotated_at',
    ];

    protected $hidden = [
        'token',
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'rotated_at' => 'datetime',
        ];
    }

    public function raidPlan(): BelongsTo
    {
        return $this->belongsTo(RaidPlan::class);
    }
}
