<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CharacterTransfer extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['completed_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CharacterTransferRun::class);
    }
}
