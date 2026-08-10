<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccultProgress extends Model
{
    use HasFactory;

    public const DATA_SOURCE_FFLOGS = 'fflogs';

    public const DATA_SOURCE_LODESTONE_ACHIEVEMENT = 'lodestone_achievement';

    protected $fillable = [
        'character_id',
        'data_source',
        'forked_tower_magic_data_source',
        'knowledge_level',
        'demon_tablet_kills',
        'demon_tablet_progress',
        'dead_stars_kills',
        'dead_stars_progress',
        'marble_dragon_kills',
        'marble_dragon_progress',
        'magitaur_kills',
        'magitaur_progress',
        'two_headed_aevis_kills',
        'two_headed_aevis_progress',
        'sword_dancer_kills',
        'sword_dancer_progress',
        'necrophobia_kills',
        'necrophobia_progress',
        'index_kills',
        'index_progress',
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
        'two_headed_aevis_kills' => 'integer',
        'two_headed_aevis_progress' => 'integer',
        'sword_dancer_kills' => 'integer',
        'sword_dancer_progress' => 'integer',
        'necrophobia_kills' => 'integer',
        'necrophobia_progress' => 'integer',
        'index_kills' => 'integer',
        'index_progress' => 'integer',
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
            'data_source' => $this->data_source ?? self::DATA_SOURCE_FFLOGS,
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

    public function forkedTowerMagicProgress(): array
    {
        return [
            'clears' => $this->index_kills,
            'data_source' => $this->forked_tower_magic_data_source ?? self::DATA_SOURCE_FFLOGS,
            'bosses' => [
                [
                    'key' => 'two_headed_aevis',
                    'kills' => $this->two_headed_aevis_kills,
                    'progress' => $this->two_headed_aevis_progress,
                ],
                [
                    'key' => 'sword_dancer',
                    'kills' => $this->sword_dancer_kills,
                    'progress' => $this->sword_dancer_progress,
                ],
                [
                    'key' => 'necrophobia',
                    'kills' => $this->necrophobia_kills,
                    'progress' => $this->necrophobia_progress,
                ],
                [
                    'key' => 'index',
                    'kills' => $this->index_kills,
                    'progress' => $this->index_progress,
                ],
            ],
        ];
    }
}
