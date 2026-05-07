<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMembership extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_MEMBER = 'member';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_MODERATOR,
        self::ROLE_MEMBER,
    ];

    protected $fillable = [
        'group_id',
        'user_id',
        'role',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (GroupMembership $membership): void {
            $membership->group()
                ->first()?->followers()
                ->syncWithoutDetaching([$membership->user_id]);
        });

        static::deleted(function (GroupMembership $membership): void {
            $membership->group()
                ->first()?->followers()
                ->detach($membership->user_id);
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
