<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMembership extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleted(function (GroupMembership $membership): void {
            User::query()
                ->whereKey($membership->user_id)
                ->where('homepage_group_id', $membership->group_id)
                ->update(['homepage_group_id' => null]);
        });
    }

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MODERATOR = 'moderator';

    public const ROLE_MEMBER = 'member';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_MODERATOR,
        self::ROLE_MEMBER,
    ];

    protected $fillable = [
        'group_id',
        'user_id',
        'role',
        'joined_at',
        'notifications_enabled',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'notifications_enabled' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
