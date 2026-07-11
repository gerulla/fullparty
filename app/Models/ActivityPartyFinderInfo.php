<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityPartyFinderInfo extends Model
{
    protected $table = 'activity_party_finder_info';

    protected $fillable = [
        'activity_id',
        'character_name',
        'world',
        'password',
        'published_by_user_id',
        'published_at',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'published_at' => 'datetime',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
