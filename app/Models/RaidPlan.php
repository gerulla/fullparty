<?php

namespace App\Models;

use Database\Factories\RaidPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RaidPlan extends Model
{
    /** @use HasFactory<RaidPlanFactory> */
    use HasFactory, SoftDeletes;

    public const VISIBILITY_UNLISTED = 'unlisted';

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITIES = [
        self::VISIBILITY_UNLISTED,
        self::VISIBILITY_PUBLIC,
    ];

    protected $fillable = [
        'author_id',
        'activity_type_id',
        'name',
        'description',
        'visibility',
    ];

    protected $attributes = [
        'visibility' => self::VISIBILITY_UNLISTED,
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function accessLinks(): HasMany
    {
        return $this->hasMany(RaidPlanAccessLink::class);
    }

    public function fight(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
    }

    public function accessToken(string $permission): string
    {
        $this->loadMissing('accessLinks');
        $accessLink = $this->accessLinks->firstWhere('permission', $permission);

        if (! $accessLink) {
            throw (new ModelNotFoundException)->setModel(RaidPlanAccessLink::class);
        }

        return $accessLink->token;
    }
}
