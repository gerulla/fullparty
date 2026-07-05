<?php

namespace App\Support\SeedData;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ReferenceIconCatalog
{
    /**
     * @return array<int, array<string, string|int>>
     */
    public static function characterClasses(): array
    {
        return array_map(function (array $characterClass): array {
            $slug = strtolower($characterClass['shorthand']);
            $iconPublicPath = "seed-data/character-classes/icons/{$slug}.png";
            $flatIconPublicPath = "seed-data/character-classes/flat-icons/{$slug}.png";
            $iconStoragePath = "reference-icons/character-classes/icons/{$slug}.webp";
            $flatIconStoragePath = "reference-icons/character-classes/flat-icons/{$slug}.webp";

            return [
                'name' => $characterClass['name'],
                'shorthand' => $characterClass['shorthand'],
                'role' => self::normalizeCharacterClassRole($characterClass['role']),
                'icon_source_url' => $characterClass['icon_source_url'],
                'icon_public_path' => $iconPublicPath,
                'icon_storage_path' => $iconStoragePath,
                'icon_url' => self::preferredSeedUrl($iconStoragePath, $iconPublicPath, $characterClass['icon_source_url']),
                'flaticon_source_url' => $characterClass['flaticon_source_url'],
                'flaticon_public_path' => $flatIconPublicPath,
                'flaticon_storage_path' => $flatIconStoragePath,
                'flaticon_url' => self::preferredSeedUrl($flatIconStoragePath, $flatIconPublicPath, $characterClass['flaticon_source_url']),
            ];
        }, self::CHARACTER_CLASSES);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    public static function phantomJobs(): array
    {
        return array_map(function (array $phantomJob): array {
            $slug = Str::slug($phantomJob['name']);
            $iconPublicPath = "seed-data/phantom-jobs/icons/{$slug}.png";
            $blackIconPublicPath = "seed-data/phantom-jobs/black-icons/{$slug}.png";
            $transparentIconPublicPath = "seed-data/phantom-jobs/transparent-icons/{$slug}.png";
            $spritePublicPath = "seed-data/phantom-jobs/sprites/{$slug}.png";
            $iconStoragePath = "reference-icons/phantom-jobs/icons/{$slug}.webp";
            $blackIconStoragePath = "reference-icons/phantom-jobs/black-icons/{$slug}.webp";
            $transparentIconStoragePath = "reference-icons/phantom-jobs/transparent-icons/{$slug}.webp";
            $spriteStoragePath = "reference-icons/phantom-jobs/sprites/{$slug}.webp";

            return [
                'name' => $phantomJob['name'],
                'max_level' => $phantomJob['max_level'],
                'icon_source_url' => $phantomJob['icon_source_url'],
                'icon_public_path' => $iconPublicPath,
                'icon_storage_path' => $iconStoragePath,
                'icon_url' => self::preferredSeedUrl($iconStoragePath, $iconPublicPath, $phantomJob['icon_source_url']),
                'black_icon_source_url' => $phantomJob['black_icon_source_url'],
                'black_icon_public_path' => $blackIconPublicPath,
                'black_icon_storage_path' => $blackIconStoragePath,
                'black_icon_url' => self::preferredSeedUrl($blackIconStoragePath, $blackIconPublicPath, $phantomJob['black_icon_source_url']),
                'transparent_icon_source_url' => $phantomJob['transparent_icon_source_url'],
                'transparent_icon_public_path' => $transparentIconPublicPath,
                'transparent_icon_storage_path' => $transparentIconStoragePath,
                'transparent_icon_url' => self::preferredSeedUrl($transparentIconStoragePath, $transparentIconPublicPath, $phantomJob['transparent_icon_source_url']),
                'sprite_source_url' => $phantomJob['sprite_source_url'],
                'sprite_public_path' => $spritePublicPath,
                'sprite_storage_path' => $spriteStoragePath,
                'sprite_url' => self::preferredSeedUrl($spriteStoragePath, $spritePublicPath, $phantomJob['sprite_source_url']),
            ];
        }, self::PHANTOM_JOBS);
    }

    /**
     * @return array<int, array{label: string, source_url: string, public_path: string}>
     */
    public static function downloadEntries(): array
    {
        $downloads = [];

        foreach (self::characterClasses() as $characterClass) {
            $downloads[] = [
                'label' => sprintf('Character class %s icon', $characterClass['shorthand']),
                'source_url' => $characterClass['icon_source_url'],
                'public_path' => $characterClass['icon_public_path'],
            ];
            $downloads[] = [
                'label' => sprintf('Character class %s flat icon', $characterClass['shorthand']),
                'source_url' => $characterClass['flaticon_source_url'],
                'public_path' => $characterClass['flaticon_public_path'],
            ];
        }

        foreach (self::phantomJobs() as $phantomJob) {
            $downloads[] = [
                'label' => sprintf('Phantom job %s icon', $phantomJob['name']),
                'source_url' => $phantomJob['icon_source_url'],
                'public_path' => $phantomJob['icon_public_path'],
            ];
            $downloads[] = [
                'label' => sprintf('Phantom job %s black icon', $phantomJob['name']),
                'source_url' => $phantomJob['black_icon_source_url'],
                'public_path' => $phantomJob['black_icon_public_path'],
            ];
            $downloads[] = [
                'label' => sprintf('Phantom job %s transparent icon', $phantomJob['name']),
                'source_url' => $phantomJob['transparent_icon_source_url'],
                'public_path' => $phantomJob['transparent_icon_public_path'],
            ];
            $downloads[] = [
                'label' => sprintf('Phantom job %s sprite', $phantomJob['name']),
                'source_url' => $phantomJob['sprite_source_url'],
                'public_path' => $phantomJob['sprite_public_path'],
            ];
        }

        return $downloads;
    }

    private static function publicUrl(string $path): string
    {
        return '/'.ltrim(str_replace('\\', '/', $path), '/');
    }

    private static function seedUrl(string $publicPath, string $sourceUrl): string
    {
        return is_file(public_path($publicPath))
            ? self::publicUrl($publicPath)
            : $sourceUrl;
    }

    private static function preferredSeedUrl(string $storagePath, string $publicPath, string $sourceUrl): string
    {
        if (is_file(storage_path('app/public/'.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storagePath)))) {
            return Storage::disk('public')->url($storagePath);
        }

        $webpPath = preg_replace('/\.[^\.\/\\\\]+$/', '.webp', $publicPath);

        if (is_string($webpPath) && is_file(public_path($webpPath))) {
            return self::publicUrl($webpPath);
        }

        return self::seedUrl($publicPath, $sourceUrl);
    }

    private static function normalizeCharacterClassRole(string $role): string
    {
        return match ($role) {
            'meleedps' => 'melee dps',
            'magical ranged dps' => 'magic ranged dps',
            default => $role,
        };
    }

    private const CHARACTER_CLASSES = [
        [
            'name' => 'Bard',
            'shorthand' => 'BRD',
            'role' => 'physical ranged dps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/b/b3/Bard_Icon_3.png/96px-Bard_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/c/cf/Bard_Icon_10.png',
        ],
        [
            'name' => 'Dragoon',
            'shorthand' => 'DRG',
            'role' => 'meleedps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/2/21/Dragoon_Icon_3.png/96px-Dragoon_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/c/ca/Dragoon_Icon_10.png',
        ],
        [
            'name' => 'Monk',
            'shorthand' => 'MNK',
            'role' => 'meleedps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/f/f9/Monk_Icon_3.png/96px-Monk_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/8/80/Monk_Icon_10.png',
        ],
        [
            'name' => 'Paladin',
            'shorthand' => 'PLD',
            'role' => 'tank',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/66/Paladin_Icon_3.png/96px-Paladin_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/7/74/Paladin_Icon_10.png',
        ],
        [
            'name' => 'Warrior',
            'shorthand' => 'WAR',
            'role' => 'tank',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/1/16/Warrior_Icon_3.png/96px-Warrior_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/6/68/Warrior_Icon_10.png',
        ],
        [
            'name' => 'Black Mage',
            'shorthand' => 'BLM',
            'role' => 'magical ranged dps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/5/51/Black_Mage_Icon_3.png/96px-Black_Mage_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/1/1f/Black_Mage_Icon_10.png',
        ],
        [
            'name' => 'White Mage',
            'shorthand' => 'WHM',
            'role' => 'healer',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/d/db/White_Mage_Icon_3.png/96px-White_Mage_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/6/6c/White_Mage_Icon_10.png',
        ],
        [
            'name' => 'Scholar',
            'shorthand' => 'SCH',
            'role' => 'healer',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/e/e0/Scholar_Icon_3.png/96px-Scholar_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/9/90/Scholar_Icon_10.png',
        ],
        [
            'name' => 'Summoner',
            'shorthand' => 'SMN',
            'role' => 'magical ranged dps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/f/f6/Summoner_Icon_3.png/96px-Summoner_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/4/4e/Summoner_Icon_10.png',
        ],
        [
            'name' => 'Ninja',
            'shorthand' => 'NIN',
            'role' => 'meleedps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/c/c8/Ninja_Icon_3.png/96px-Ninja_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/c/c5/Ninja_Icon_10.png',
        ],
        [
            'name' => 'Astrologian',
            'shorthand' => 'AST',
            'role' => 'healer',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/f/fb/Astrologian_Icon_3.png/96px-Astrologian_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/4/46/Astrologian_Icon_10.png',
        ],
        [
            'name' => 'Dark Knight',
            'shorthand' => 'DRK',
            'role' => 'tank',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/b/bd/Dark_Knight_Icon_3.png/96px-Dark_Knight_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/e/e3/Dark_Knight_Icon_10.png',
        ],
        [
            'name' => 'Machinist',
            'shorthand' => 'MCH',
            'role' => 'physical ranged dps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/9/99/Machinist_Icon_3.png/96px-Machinist_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/2/23/Machinist_Icon_10.png',
        ],
        [
            'name' => 'Red Mage',
            'shorthand' => 'RDM',
            'role' => 'magical ranged dps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/64/Red_Mage_Icon_3.png/96px-Red_Mage_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/e/e0/Red_Mage_Icon_10.png',
        ],
        [
            'name' => 'Samurai',
            'shorthand' => 'SAM',
            'role' => 'meleedps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/9/98/Samurai_Icon_3.png/96px-Samurai_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/6/61/Samurai_Icon_10.png',
        ],
        [
            'name' => 'Blue Mage',
            'shorthand' => 'BLU',
            'role' => 'magical ranged dps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/4/4b/Blue_Mage_Icon_3.png/96px-Blue_Mage_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/0/08/Blue_Mage_Icon_10.png',
        ],
        [
            'name' => 'Gunbreaker',
            'shorthand' => 'GNB',
            'role' => 'tank',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/b/b9/Gunbreaker_Icon_3.png/96px-Gunbreaker_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/8/87/Gunbreaker_Icon_10.png',
        ],
        [
            'name' => 'Dancer',
            'shorthand' => 'DNC',
            'role' => 'physical ranged dps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/3/3e/Dancer_Icon_3.png/96px-Dancer_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/1/15/Dancer_Icon_10.png',
        ],
        [
            'name' => 'Reaper',
            'shorthand' => 'RPR',
            'role' => 'meleedps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/1/19/Reaper_Icon_3.png/96px-Reaper_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/e/ec/Reaper_Icon_10.png',
        ],
        [
            'name' => 'Sage',
            'shorthand' => 'SGE',
            'role' => 'healer',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/d/d7/Sage_Icon_3.png/96px-Sage_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/3/3d/Sage_Icon_10.png',
        ],
        [
            'name' => 'Viper',
            'shorthand' => 'VPR',
            'role' => 'meleedps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/e/e7/Viper_Icon_3.png/96px-Viper_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/2/22/Viper_Icon_10.png',
        ],
        [
            'name' => 'Pictomancer',
            'shorthand' => 'PCT',
            'role' => 'magical ranged dps',
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/d/de/Pictomancer_Icon_3.png/96px-Pictomancer_Icon_3.png',
            'flaticon_source_url' => 'https://ffxiv.gamerescape.com/w/images/1/13/Pictomancer_Icon_10.png',
        ],
    ];

    private const PHANTOM_JOBS = [
        [
            'name' => 'Phantom Knight',
            'max_level' => 6,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/a/a9/Phantom_Knight_Icon_2.png/24px-Phantom_Knight_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/3/3c/Phantom_Knight_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/0/EeAUQGbPRholS0vdUUpQy-xpMg.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/s/A3-SG0tFwZO9pVR75gUloJoSQ0.png',
        ],
        [
            'name' => 'Phantom Monk',
            'max_level' => 6,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/2/23/Phantom_Monk_Icon_2.png/24px-Phantom_Monk_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/1/10/Phantom_Monk_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/1/Ytrt4rKZ9VtkThzraLOpZDcTJM.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/7/dn7C191l0vq3TtiRFR944Fb-Hc.png',
        ],
        [
            'name' => 'Phantom Thief',
            'max_level' => 6,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/4/48/Phantom_Thief_Icon_2.png/24px-Phantom_Thief_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/7/7d/Phantom_Thief_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/q/yB4sS-edFgJZr9zkj_ZhHmrdVs.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/E/Yfd8jLT0bCygTbggkpZQy4ggqY.png',
        ],
        [
            'name' => 'Phantom Samurai',
            'max_level' => 5,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/3/3e/Phantom_Samurai_Icon_2.png/24px-Phantom_Samurai_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/c/c0/Phantom_Samurai_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/a/aC1ZyKgdlf4rm7EqsynZabqjk0.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/K/3-EIjmHiXIAXjta5tOD8w3FqGE.png',
        ],
        [
            'name' => 'Phantom Berserker',
            'max_level' => 3,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/1/1d/Phantom_Berserker_Icon_2.png/24px-Phantom_Berserker_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/c/c3/Phantom_Berserker_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/1/i7_i2o3SMpWm3UL9DADFNeG82E.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/X/rLD12b3ctDWjOOIGjyCLiaxFDw.png',
        ],
        [
            'name' => 'Phantom Ranger',
            'max_level' => 6,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/5/57/Phantom_Ranger_Icon_2.png/24px-Phantom_Ranger_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/7/78/Phantom_Ranger_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/C/Lac7FfO38xNmTl2qELQOKG4QQw.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/0/RdMuMVdDWzqguuOkhku6s6n80s.png',
        ],
        [
            'name' => 'Phantom Mystic Knight',
            'max_level' => 4,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/64/Phantom_Mystic_Knight_Icon_2.png/24px-Phantom_Mystic_Knight_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/3/3b/Phantom_Mystic_Knight_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/t/l5wEwRwz-8JznV9PqhM9eMTdg4.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/4/6kfthKEhpe-sQ_BvBDTcDMqol8.png',
        ],
        [
            'name' => 'Phantom Time Mage',
            'max_level' => 5,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/e/e1/Phantom_Time_Mage_Icon_2.png/24px-Phantom_Time_Mage_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/8/84/Phantom_Time_Mage_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/S/WdoHHqrJWPJCQDkLaT167PVg2M.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/n/ZIdqqxYvR36UyoNSD-LGQ4zyX0.png',
        ],
        [
            'name' => 'Phantom Chemist',
            'max_level' => 4,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/3/35/Phantom_Chemist_Icon_2.png/24px-Phantom_Chemist_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/f/fd/Phantom_Chemist_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/Y/6yGkfR7vOy3F6hgCyB-rzZgqEs.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/U/6IadmelelH4pbxErgBY5Fh5rMw.png',
        ],
        [
            'name' => 'Phantom Geomancer',
            'max_level' => 5,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/61/Phantom_Geomancer_Icon_2.png/24px-Phantom_Geomancer_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/2/26/Phantom_Geomancer_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/k/E0TfYJCHf9kTCc18Vo84oSnl78.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/6/LU5ckrWlmoWTkGvVftNfpPtufQ.png',
        ],
        [
            'name' => 'Phantom Bard',
            'max_level' => 4,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/a/ac/Phantom_Bard_Icon_2.png/24px-Phantom_Bard_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/7/73/Phantom_Bard_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/q/kdRxGWaIMcO29FjivHfD3zPB80.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/R/dbBP-BFbJnDjyZqHrA3i3b-Nb0.png',
        ],
        [
            'name' => 'Phantom Dancer',
            'max_level' => 4,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/62/Phantom_Dancer_Icon_2.png/24px-Phantom_Dancer_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/d/d7/Phantom_Dancer_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/w/t9R7wDwdNEmxZhY8emmSDkYTKg.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/n/84NMryQaaZ0r3zRyYp9HurQ0SY.png',
        ],
        [
            'name' => 'Phantom Oracle',
            'max_level' => 5,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/b/b2/Phantom_Oracle_Icon_2.png/24px-Phantom_Oracle_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/4/4d/Phantom_Oracle_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/x/Hj3YuDplfAB5jOKPpvPYVTMilY.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/g/M3CgKEMRjbXYLXx4wfcuJ64ong.png',
        ],
        [
            'name' => 'Phantom Cannoneer',
            'max_level' => 6,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/5/5b/Phantom_Cannoneer_Icon_2.png/24px-Phantom_Cannoneer_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/1/17/Phantom_Cannoneer_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/U/dQZpbJaqEgFhqnTmg6osWgBczA.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/S/G0meGtQ8_WR4Oz8YRpui6LYBqA.png',
        ],
        [
            'name' => 'Phantom Gladiator',
            'max_level' => 4,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/65/Phantom_Gladiator_Icon_2.png/24px-Phantom_Gladiator_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/c/c7/Phantom_Gladiator_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/I/G8r27uAMqTSbEgyfFcecG3cbTo.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/r/SgAkfkF7k2P1HBMlgD0pqamsIs.png',
        ],
        [
            'name' => 'Phantom Freelancer',
            'max_level' => 16,
            'icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/0/09/Phantom_Freelancer_Icon_2.png/24px-Phantom_Freelancer_Icon_2.png',
            'black_icon_source_url' => 'https://ffxiv.gamerescape.com/w/images/2/23/Phantom_Freelancer_Icon.png',
            'sprite_source_url' => 'https://lds-img.finalfantasyxiv.com/h/X/zanwwu69-0p23AHZWlg5_Jhzc8.png',
            'transparent_icon_source_url' => 'https://lds-img.finalfantasyxiv.com/h/Z/BPP6fZ59aZG1vWV0FN_-DNtK9c.png',
        ],
    ];
}
