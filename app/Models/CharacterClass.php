<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CharacterClass extends Model
{
    use HasFactory;

    public const ROLES = [
        'healer',
        'tank',
        'melee dps',
        'magic ranged dps',
        'physical ranged dps',
    ];

    protected $fillable = [
        'name',
        'shorthand',
        'icon_url',
        'flaticon_url',
        'role',
    ];

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class, 'character_class_character')
            ->using(CharacterClassProgress::class)
            ->withPivot(['level', 'is_preferred'])
            ->withTimestamps();
    }
}
