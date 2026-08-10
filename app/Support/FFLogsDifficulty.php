<?php

namespace App\Support;

use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use Illuminate\Support\Str;

final class FFLogsDifficulty
{
    public const HIGH_END = 101;

    public const FORKED_TOWER_MAGIC_EXTREME = self::HIGH_END;

    public const DELUBRUM_REGINAE_SAVAGE = self::HIGH_END;

    public const DELUBRUM_REGINAE_SAVAGE_ZONE_ID = 60;

    public static function forActivityTypeVersion(?ActivityTypeVersion $activityTypeVersion): ?int
    {
        if (! $activityTypeVersion) {
            return null;
        }

        $zoneDifficulty = self::forActivityZone((int) ($activityTypeVersion->fflogs_zone_id ?? 0));

        if ($zoneDifficulty !== null) {
            return $zoneDifficulty;
        }

        if ((string) $activityTypeVersion->difficulty === ActivityType::DIFFICULTY_SAVAGE) {
            return self::HIGH_END;
        }

        if (self::localizedNameContains($activityTypeVersion->name, 'savage')) {
            return self::DELUBRUM_REGINAE_SAVAGE;
        }

        return null;
    }

    public static function forActivityZone(int $zoneId): ?int
    {
        $forkedTowerMagicZoneId = (int) config('services.ff_logs.forked_tower_magic_zone_id');

        if ($forkedTowerMagicZoneId > 0 && $zoneId === $forkedTowerMagicZoneId) {
            return self::FORKED_TOWER_MAGIC_EXTREME;
        }

        if ($zoneId === self::DELUBRUM_REGINAE_SAVAGE_ZONE_ID) {
            return self::DELUBRUM_REGINAE_SAVAGE;
        }

        return null;
    }

    private static function localizedNameContains(mixed $name, string $needle): bool
    {
        $values = is_array($name) ? $name : [$name];

        foreach ($values as $value) {
            if (is_string($value) && Str::of($value)->lower()->contains($needle)) {
                return true;
            }
        }

        return false;
    }
}
