<?php

use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Support\FFLogsDifficulty;

it('resolves high-end ff logs difficulty for savage activity versions', function () {
    $version = new ActivityTypeVersion([
        'name' => ['en' => 'The Arcadion (Savage)'],
        'difficulty' => ActivityType::DIFFICULTY_SAVAGE,
        'fflogs_zone_id' => 44,
    ]);

    expect(FFLogsDifficulty::forActivityTypeVersion($version))->toBe(FFLogsDifficulty::HIGH_END);
});

it('resolves delubrum reginae savage by its production ff logs zone', function () {
    $version = new ActivityTypeVersion([
        'name' => ['en' => 'Delubrum Reginae (Savage)'],
        'difficulty' => ActivityType::DIFFICULTY_EXPLORATION,
        'fflogs_zone_id' => FFLogsDifficulty::DELUBRUM_REGINAE_SAVAGE_ZONE_ID,
    ]);

    expect(FFLogsDifficulty::forActivityTypeVersion($version))->toBe(FFLogsDifficulty::DELUBRUM_REGINAE_SAVAGE);
});

it('keeps normal activity versions on the default ff logs difficulty', function () {
    $version = new ActivityTypeVersion([
        'name' => ['en' => 'Delubrum Reginae'],
        'difficulty' => ActivityType::DIFFICULTY_NORMAL,
        'fflogs_zone_id' => 69,
    ]);

    expect(FFLogsDifficulty::forActivityTypeVersion($version))->toBeNull();
});
