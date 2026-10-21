<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityGroupFollowUp extends Model
{
    public const JOIN_GROUP = 'join_group';

    public const INVITE_CANDIDATE = 'invite_candidate';

    public const INVITE_PARTICIPANTS = 'invite_participants';

    protected $fillable = ['activity_id', 'user_id', 'kind', 'characters', 'assignment_ids', 'presented_at', 'dismissed_at'];

    protected $casts = [
        'characters' => 'array',
        'assignment_ids' => 'array',
        'presented_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
