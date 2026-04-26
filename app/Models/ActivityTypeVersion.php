<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityTypeVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'activity_type_id',
        'version',
        'name',
        'description',
        'layout_schema',
        'slot_schema',
        'application_schema',
        'progress_schema',
        'bench_size',
        'prog_points',
        'fflogs_zone_id',
        'published_by_user_id',
        'published_at',
    ];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'layout_schema' => 'array',
        'slot_schema' => 'array',
        'application_schema' => 'array',
        'progress_schema' => 'array',
        'bench_size' => 'integer',
        'prog_points' => 'array',
        'fflogs_zone_id' => 'integer',
        'published_at' => 'datetime',
    ];

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
