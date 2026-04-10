<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PhantomJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'max_level',
        'icon_url',
        'black_icon_url',
        'transparent_icon_url',
        'sprite_url',
    ];

    protected $casts = [
        'max_level' => 'integer',
    ];

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class, 'character_phantom_job')
            ->using(PhantomJobProgress::class)
            ->withPivot(['current_level', 'is_preferred'])
            ->withTimestamps();
    }
}
