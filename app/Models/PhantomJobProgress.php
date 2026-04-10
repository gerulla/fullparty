<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PhantomJobProgress extends Pivot
{
    protected $table = 'character_phantom_job';

    protected $fillable = [
        'character_id',
        'phantom_job_id',
        'current_level',
        'is_preferred',
    ];

    protected $casts = [
        'current_level' => 'integer',
        'is_preferred' => 'boolean',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function phantomJob(): BelongsTo
    {
        return $this->belongsTo(PhantomJob::class);
    }
}
