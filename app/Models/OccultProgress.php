<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccultProgress extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'knowledge_level',
        'demon_tablet_kills',
        'demon_tablet_progress',
        'dead_stars_kills',
        'dead_stars_progress',
        'marble_dragon_kills',
        'marble_dragon_progress',
        'magitaur_kills',
        'magitaur_progress',
    ];

    protected $casts = [
        'knowledge_level' => 'integer',
        'demon_tablet_kills' => 'integer',
        'demon_tablet_progress' => 'integer',
        'dead_stars_kills' => 'integer',
        'dead_stars_progress' => 'integer',
        'marble_dragon_kills' => 'integer',
        'marble_dragon_progress' => 'integer',
        'magitaur_kills' => 'integer',
        'magitaur_progress' => 'integer',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function clears(): int
    {
        return $this->magitaur_kills;
    }

    public function forkedTowerBloodProgress(): array
    {
        return [
            'clears' => $this->clears(),
            'bosses' => [
                [
                    'key' => 'demon_tablet',
                    'kills' => $this->demon_tablet_kills,
                    'progress' => $this->demon_tablet_progress,
                ],
                [
                    'key' => 'dead_stars',
                    'kills' => $this->dead_stars_kills,
                    'progress' => $this->dead_stars_progress,
                ],
                [
                    'key' => 'marble_dragon',
                    'kills' => $this->marble_dragon_kills,
                    'progress' => $this->marble_dragon_progress,
                ],
                [
                    'key' => 'magitaur',
                    'kills' => $this->magitaur_kills,
                    'progress' => $this->magitaur_progress,
                ],
            ],
        ];
    }
}
