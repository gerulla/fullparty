<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CharacterClassProgress extends Pivot
{
    protected $table = 'character_class_character';

    protected $fillable = [
        'character_id',
        'character_class_id',
        'level',
        'is_preferred',
    ];

    protected $casts = [
        'level' => 'integer',
        'is_preferred' => 'boolean',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function characterClass(): BelongsTo
    {
        return $this->belongsTo(CharacterClass::class);
    }
}
