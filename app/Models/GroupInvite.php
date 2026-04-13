<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupInvite extends Model
{
    use HasFactory;

    private const TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const TOKEN_LENGTH = 10;

    protected $fillable = [
        'group_id',
        'created_by',
        'token',
        'max_uses',
        'uses',
        'expires_at',
        'is_system',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_system' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasRemainingUses(): bool
    {
        return $this->max_uses === null || $this->uses < $this->max_uses;
    }

    public function canBeAccepted(): bool
    {
        return !$this->isExpired() && $this->hasRemainingUses();
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = static::generateToken();
        } while (static::query()->where('token', $token)->exists());

        return $token;
    }

    private static function generateToken(): string
    {
        $characters = self::TOKEN_CHARACTERS;
        $characterCount = strlen($characters);
        $token = '';

        for ($index = 0; $index < self::TOKEN_LENGTH; $index++) {
            $token .= $characters[random_int(0, $characterCount - 1)];
        }

        return $token;
    }
}
