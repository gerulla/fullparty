<?php

namespace App\Support\Bozja;

final class BozjaItemCategory
{
    public const BANNERS = 'banners';

    public const ESSENCES = 'essences';

    public const DEEP_ESSENCES = 'deep_essences';

    public const PURE_ESSENCES = 'pure_essences';

    public const LOST_ACTIONS = 'lost_actions';

    public const LOST_ITEMS = 'lost_items';

    public const VALUES = [
        self::BANNERS,
        self::ESSENCES,
        self::DEEP_ESSENCES,
        self::PURE_ESSENCES,
        self::LOST_ACTIONS,
        self::LOST_ITEMS,
    ];

    /**
     * @return array<string, string>
     */
    public static function sourceCategoryMap(): array
    {
        return [
            'bozja_banners' => self::BANNERS,
            'bozja_essences' => self::ESSENCES,
            'bozja_deep_essences' => self::DEEP_ESSENCES,
            'bozja_pure_essences' => self::PURE_ESSENCES,
            'bozja_lost_actions' => self::LOST_ACTIONS,
            'bozja_lost_items' => self::LOST_ITEMS,
        ];
    }

    public static function categoryForSource(?string $source): ?string
    {
        return self::sourceCategoryMap()[$source] ?? null;
    }

    public static function categoryForClassification(string $classification): ?string
    {
        return match ($classification) {
            'banner' => self::BANNERS,
            'essence' => self::ESSENCES,
            'deep_essence' => self::DEEP_ESSENCES,
            'pure_essence' => self::PURE_ESSENCES,
            'lost_action' => self::LOST_ACTIONS,
            'lost_item' => self::LOST_ITEMS,
            default => null,
        };
    }
}
