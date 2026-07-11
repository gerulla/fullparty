<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupAvailabilitySetting extends Model
{
    public const MINIMUM_ROLE_MEMBER = 'member';

    public const MINIMUM_ROLE_MODERATOR = 'moderator';

    public const MINIMUM_ROLES = [
        self::MINIMUM_ROLE_MEMBER,
        self::MINIMUM_ROLE_MODERATOR,
    ];

    protected $fillable = [
        'group_id',
        'minimum_role',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
