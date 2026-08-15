<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotaOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'quota_key',
        'limit',
        'is_unlimited',
        'starts_at',
        'expires_at',
        'reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'limit' => 'integer',
            'is_unlimited' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $query) => $query
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }
}
