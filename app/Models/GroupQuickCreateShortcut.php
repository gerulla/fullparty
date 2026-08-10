<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupQuickCreateShortcut extends Model
{
    use HasFactory;

    public const TIME_MODE_SERVER = 'server';

    public const TIME_MODE_LOCAL = 'local';

    public const TIME_MODES = [
        self::TIME_MODE_SERVER,
        self::TIME_MODE_LOCAL,
    ];

    public const MAX_SHORTCUTS = 5;

    protected $fillable = [
        'group_id',
        'time_of_day',
        'time_mode',
        'sort_order',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return array<int, array{time_of_day: string, time_mode: string, sort_order: int}> */
    public static function defaults(): array
    {
        return [
            ['time_of_day' => '18:00', 'time_mode' => self::TIME_MODE_SERVER, 'sort_order' => 0],
            ['time_of_day' => '20:00', 'time_mode' => self::TIME_MODE_SERVER, 'sort_order' => 1],
            ['time_of_day' => '22:00', 'time_mode' => self::TIME_MODE_SERVER, 'sort_order' => 2],
        ];
    }
}
