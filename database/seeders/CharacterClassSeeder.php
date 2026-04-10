<?php

namespace Database\Seeders;

use App\Models\CharacterClass;
use Illuminate\Database\Seeder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CharacterClassSeeder extends Seeder
{
    /**
     * Seed the application's character classes.
     */
    public function run(): void
    {
        Storage::disk('public')->deleteDirectory('character-classes');

        foreach ($this->classes() as $class) {
            CharacterClass::updateOrCreate(
                ['shorthand' => $class['shorthand']],
                [
                    'name' => $class['name'],
                    'role' => $this->normalizeRole($class['role']),
                    'icon_url' => $this->downloadImage($class['icon_url'], 'icons'),
                    'flaticon_url' => $this->downloadImage($class['flaticon_url'], 'flat-icons'),
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function classes(): array
    {
        return [
            [
                'name' => 'Bard',
                'shorthand' => 'BRD',
                'role' => 'physical ranged dps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/b/b3/Bard_Icon_3.png/96px-Bard_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/c/cf/Bard_Icon_10.png',
            ],
            [
                'name' => 'Dragoon',
                'shorthand' => 'DRG',
                'role' => 'meleedps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/2/21/Dragoon_Icon_3.png/96px-Dragoon_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/c/ca/Dragoon_Icon_10.png',
            ],
            [
                'name' => 'Monk',
                'shorthand' => 'MNK',
                'role' => 'meleedps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/f/f9/Monk_Icon_3.png/96px-Monk_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/8/80/Monk_Icon_10.png',
            ],
            [
                'name' => 'Paladin',
                'shorthand' => 'PLD',
                'role' => 'tank',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/66/Paladin_Icon_3.png/96px-Paladin_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/7/74/Paladin_Icon_10.png',
            ],
            [
                'name' => 'Warrior',
                'shorthand' => 'WAR',
                'role' => 'tank',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/1/16/Warrior_Icon_3.png/96px-Warrior_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/6/68/Warrior_Icon_10.png',
            ],
            [
                'name' => 'Black Mage',
                'shorthand' => 'BLM',
                'role' => 'magical ranged dps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/5/51/Black_Mage_Icon_3.png/96px-Black_Mage_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/1/1f/Black_Mage_Icon_10.png',
            ],
            [
                'name' => 'White Mage',
                'shorthand' => 'WHM',
                'role' => 'healer',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/d/db/White_Mage_Icon_3.png/96px-White_Mage_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/6/6c/White_Mage_Icon_10.png',
            ],
            [
                'name' => 'Scholar',
                'shorthand' => 'SCH',
                'role' => 'healer',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/e/e0/Scholar_Icon_3.png/96px-Scholar_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/9/90/Scholar_Icon_10.png',
            ],
            [
                'name' => 'Summoner',
                'shorthand' => 'SMN',
                'role' => 'magical ranged dps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/f/f6/Summoner_Icon_3.png/96px-Summoner_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/4/4e/Summoner_Icon_10.png',
            ],
            [
                'name' => 'Ninja',
                'shorthand' => 'NIN',
                'role' => 'meleedps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/c/c8/Ninja_Icon_3.png/96px-Ninja_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/c/c5/Ninja_Icon_10.png',
            ],
            [
                'name' => 'Astrologian',
                'shorthand' => 'AST',
                'role' => 'healer',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/f/fb/Astrologian_Icon_3.png/96px-Astrologian_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/4/46/Astrologian_Icon_10.png',
            ],
            [
                'name' => 'Dark Knight',
                'shorthand' => 'DRK',
                'role' => 'tank',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/b/bd/Dark_Knight_Icon_3.png/96px-Dark_Knight_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/e/e3/Dark_Knight_Icon_10.png',
            ],
            [
                'name' => 'Machinist',
                'shorthand' => 'MCH',
                'role' => 'physical ranged dps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/9/99/Machinist_Icon_3.png/96px-Machinist_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/2/23/Machinist_Icon_10.png',
            ],
            [
                'name' => 'Red Mage',
                'shorthand' => 'RDM',
                'role' => 'magical ranged dps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/64/Red_Mage_Icon_3.png/96px-Red_Mage_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/e/e0/Red_Mage_Icon_10.png',
            ],
            [
                'name' => 'Samurai',
                'shorthand' => 'SAM',
                'role' => 'meleedps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/9/98/Samurai_Icon_3.png/96px-Samurai_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/6/61/Samurai_Icon_10.png',
            ],
            [
                'name' => 'Blue Mage',
                'shorthand' => 'BLU',
                'role' => 'magical ranged dps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/4/4b/Blue_Mage_Icon_3.png/96px-Blue_Mage_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/0/08/Blue_Mage_Icon_10.png',
            ],
            [
                'name' => 'Gunbreaker',
                'shorthand' => 'GNB',
                'role' => 'tank',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/b/b9/Gunbreaker_Icon_3.png/96px-Gunbreaker_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/8/87/Gunbreaker_Icon_10.png',
            ],
            [
                'name' => 'Dancer',
                'shorthand' => 'DNC',
                'role' => 'physical ranged dps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/3/3e/Dancer_Icon_3.png/96px-Dancer_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/1/15/Dancer_Icon_10.png',
            ],
            [
                'name' => 'Reaper',
                'shorthand' => 'RPR',
                'role' => 'meleedps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/1/19/Reaper_Icon_3.png/96px-Reaper_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/e/ec/Reaper_Icon_10.png',
            ],
            [
                'name' => 'Sage',
                'shorthand' => 'SGE',
                'role' => 'healer',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/d/d7/Sage_Icon_3.png/96px-Sage_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/3/3d/Sage_Icon_10.png',
            ],
            [
                'name' => 'Viper',
                'shorthand' => 'VPR',
                'role' => 'meleedps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/e/e7/Viper_Icon_3.png/96px-Viper_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/2/22/Viper_Icon_10.png',
            ],
            [
                'name' => 'Pictomancer',
                'shorthand' => 'PCT',
                'role' => 'magical ranged dps',
                'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/d/de/Pictomancer_Icon_3.png/96px-Pictomancer_Icon_3.png',
                'flaticon_url' => 'https://ffxiv.gamerescape.com/w/images/1/13/Pictomancer_Icon_10.png',
            ],
        ];
    }

    private function normalizeRole(string $role): string
    {
        return match ($role) {
            'meleedps' => 'melee dps',
            'magical ranged dps' => 'magic ranged dps',
            default => $role,
        };
    }

    private function downloadImage(string $url, string $directory): string
    {
        $response = $this->fetchImage($url);
        $extension = $this->resolveExtension($response, $url);
        $path = 'character-classes/'.$directory.'/'.Str::uuid().'.'.$extension;

        Storage::disk('public')->put($path, $response->body());

        return Storage::disk('public')->url($path);
    }

    private function fetchImage(string $url): Response
    {
        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to download character class image from [{$url}].");
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if (! str_starts_with($contentType, 'image/')) {
            throw new RuntimeException("URL [{$url}] did not return an image.");
        }

        return $response;
    }

    private function resolveExtension(Response $response, string $url): string
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        $extension = match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION),
        };

        return filled($extension) ? strtolower($extension) : 'png';
    }
}
