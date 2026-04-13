<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'profile_picture_url',
        'discord_invite_url',
        'datacenter',
        'is_public',
        'is_visible',
        'slug',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_visible' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_memberships')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function invites(): HasMany
    {
        return $this->hasMany(GroupInvite::class);
    }

    public function systemInvite(): HasOne
    {
        return $this->hasOne(GroupInvite::class)->where('is_system', true);
    }

    public function scheduledRuns(): HasMany
    {
        return $this->hasMany(ScheduledRun::class);
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function isOwnedBy(?int $userId): bool
    {
        return $userId !== null && $this->owner_id === $userId;
    }

    public function hasModeratorAccess(?int $userId): bool
    {
        if ($this->isOwnedBy($userId)) {
            return true;
        }

        if ($userId === null) {
            return false;
        }

        return $this->memberships
            ->contains(fn (GroupMembership $membership) => $membership->user_id === $userId && $membership->role === GroupMembership::ROLE_MODERATOR);
    }

    public function hasMember(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->memberships
            ->contains(fn (GroupMembership $membership) => $membership->user_id === $userId);
    }

    public function ensureSystemInvite(): void
    {
        $this->invites()->updateOrCreate(
            ['is_system' => true],
            [
                'created_by' => null,
                'token' => $this->slug,
                'max_uses' => null,
                'expires_at' => null,
            ]
        );
    }

    public function removeSystemInvite(): void
    {
        $this->invites()->where('is_system', true)->delete();
    }
}
