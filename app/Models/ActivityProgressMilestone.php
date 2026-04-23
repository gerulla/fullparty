<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityProgressMilestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'activity_id',
        'milestone_key',
        'milestone_label',
        'sort_order',
        'kills',
        'best_progress_percent',
        'source',
        'notes',
    ];

    protected $casts = [
        'milestone_label' => 'array',
        'best_progress_percent' => 'decimal:2',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
