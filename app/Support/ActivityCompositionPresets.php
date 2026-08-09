<?php

namespace App\Support;

final class ActivityCompositionPresets
{
    public const LAYOUT_LIGHT_PARTY = 'light_party_4';

    public const LAYOUT_FULL_PARTY = 'full_party_8';

    public const LAYOUT_ALLIANCE = 'alliance_24';

    public const LAYOUT_LARGE_SCALE = 'large_scale_48';

    public const ROLE_TANK = 'tank';

    public const ROLE_HEALER = 'healer';

    public const ROLE_DPS = 'dps';

    private const LAYOUTS = [
        self::LAYOUT_LIGHT_PARTY => [
            'party_count' => 1,
            'party_size' => 4,
        ],
        self::LAYOUT_FULL_PARTY => [
            'party_count' => 1,
            'party_size' => 8,
        ],
        self::LAYOUT_ALLIANCE => [
            'party_count' => 3,
            'party_size' => 8,
        ],
        self::LAYOUT_LARGE_SCALE => [
            'party_count' => 6,
            'party_size' => 8,
        ],
    ];

    private const LIGHT_PARTY_COMPOSITIONS = [
        'thdd' => 'THDD',
        'tddd' => 'TDDD',
        'hddd' => 'HDDD',
        'dddd' => 'DDDD',
    ];

    private const FULL_PARTY_COMPOSITIONS = [
        'tthhdddd' => 'TTHHDDDD',
        'tthddddd' => 'TTHDDDDD',
        'thhddddd' => 'THHDDDDD',
        'thdddddd' => 'THDDDDDD',
        'hhdddddd' => 'HHDDDDDD',
        'ttdddddd' => 'TTDDDDDD',
        'ttttdddd' => 'TTTTDDDD',
    ];

    public static function layoutPresets(): array
    {
        return collect(self::LAYOUTS)
            ->map(fn (array $preset, string $key): array => [
                'key' => $key,
                'party_count' => $preset['party_count'],
                'party_size' => $preset['party_size'],
                'total_slots' => $preset['party_count'] * $preset['party_size'],
            ])
            ->values()
            ->all();
    }

    public static function compositionPresets(): array
    {
        return collect(self::compositionSets())
            ->flatMap(fn (array $compositions, int $partySize) => collect($compositions)
                ->map(fn (string $shorthand, string $key): array => [
                    'key' => $key,
                    'party_size' => $partySize,
                    'shorthand' => $shorthand,
                    'composition_hints' => self::compositionHintsForKey($key),
                ]))
            ->values()
            ->all();
    }

    public static function compositionPresetsForLayout(string $layoutKey): array
    {
        $partySize = self::partySizeForLayout($layoutKey);

        if ($partySize === null) {
            return [];
        }

        return array_values(array_filter(
            self::compositionPresets(),
            fn (array $preset): bool => $preset['party_size'] === $partySize,
        ));
    }

    public static function defaultCompositionKeyForLayout(string $layoutKey): ?string
    {
        return match (self::partySizeForLayout($layoutKey)) {
            4 => 'thdd',
            8 => 'tthhdddd',
            default => null,
        };
    }

    public static function partySizeForLayout(string $layoutKey): ?int
    {
        return self::LAYOUTS[$layoutKey]['party_size'] ?? null;
    }

    public static function layoutForGroups(array $groups): ?string
    {
        $groupCount = count($groups);

        if ($groupCount < 1) {
            return null;
        }

        $sizes = collect($groups)
            ->map(fn (array $group): int => (int) ($group['size'] ?? 0))
            ->unique()
            ->values();

        if ($sizes->count() !== 1) {
            return null;
        }

        $partySize = $sizes->first();

        foreach (self::LAYOUTS as $key => $preset) {
            if ($preset['party_count'] === $groupCount && $preset['party_size'] === $partySize) {
                return $key;
            }
        }

        return null;
    }

    public static function groupsForLayout(string $layoutKey, string $compositionKey, array $locales = ['en']): array
    {
        $layout = self::LAYOUTS[$layoutKey] ?? null;

        if ($layout === null) {
            return [];
        }

        $compositionKey = self::isCompositionKeyValidForLayout($compositionKey, $layoutKey)
            ? $compositionKey
            : (self::defaultCompositionKeyForLayout($layoutKey) ?? $compositionKey);

        $hints = self::compositionHintsForKey($compositionKey);

        return collect(range(1, $layout['party_count']))
            ->map(function (int $index) use ($layout, $compositionKey, $hints, $locales): array {
                $label = self::partyLabel($index);

                return [
                    'key' => 'party-'.strtolower(self::partyLetter($index)),
                    'label' => self::localizedLabel($label, $locales),
                    'size' => $layout['party_size'],
                    'composition_hint_key' => $compositionKey,
                    'composition_hints' => $hints,
                ];
            })
            ->all();
    }

    public static function compositionHintsForKey(string $compositionKey): array
    {
        $shorthand = self::shorthandForCompositionKey($compositionKey);

        if ($shorthand === null) {
            return [];
        }

        return collect(str_split($shorthand))
            ->map(fn (string $role, int $index): array => [
                'position' => $index + 1,
                'accepts' => [[
                    'type' => 'role',
                    'key' => self::roleKeyForSymbol($role),
                ]],
            ])
            ->filter(fn (array $hint): bool => filled($hint['accepts'][0]['key']))
            ->values()
            ->all();
    }

    public static function isCompositionKeyValidForLayout(string $compositionKey, string $layoutKey): bool
    {
        $partySize = self::partySizeForLayout($layoutKey);

        if ($partySize === null) {
            return false;
        }

        return self::isCompositionKeyValidForPartySize($compositionKey, $partySize);
    }

    public static function isCompositionKeyValidForPartySize(string $compositionKey, int $partySize): bool
    {
        return array_key_exists($compositionKey, self::compositionSets()[$partySize] ?? []);
    }

    public static function validRoleKeys(): array
    {
        return [
            self::ROLE_TANK,
            self::ROLE_HEALER,
            self::ROLE_DPS,
        ];
    }

    private static function compositionSets(): array
    {
        return [
            4 => self::LIGHT_PARTY_COMPOSITIONS,
            8 => self::FULL_PARTY_COMPOSITIONS,
        ];
    }

    private static function shorthandForCompositionKey(string $compositionKey): ?string
    {
        foreach (self::compositionSets() as $compositions) {
            if (array_key_exists($compositionKey, $compositions)) {
                return $compositions[$compositionKey];
            }
        }

        return null;
    }

    private static function roleKeyForSymbol(string $symbol): ?string
    {
        return match (strtoupper($symbol)) {
            'T' => self::ROLE_TANK,
            'H' => self::ROLE_HEALER,
            'D' => self::ROLE_DPS,
            default => null,
        };
    }

    private static function partyLetter(int $index): string
    {
        return chr(64 + $index);
    }

    private static function partyLabel(int $index): string
    {
        return 'Party '.self::partyLetter($index);
    }

    private static function localizedLabel(string $label, array $locales): array
    {
        $locales = array_values(array_unique(array_filter($locales)));

        if ($locales === []) {
            $locales = ['en'];
        }

        return collect($locales)
            ->mapWithKeys(fn (string $locale): array => [$locale => $label])
            ->all();
    }
}
