<?php

namespace App\Services\Lodestone;

use App\Models\Character;
use App\Models\OccultProgress;
use App\Services\Lodestone\Parsers\LodestoneAchievementParser;
use Illuminate\Support\Facades\Cache;

class ForkedTowerBloodAchievementProgressFetcher
{
    /**
     * @var array<int, int>
     */
    private const ACHIEVEMENT_CLEAR_FLOORS = [
        3671 => 100,
        3670 => 50,
        3669 => 10,
        3668 => 1,
    ];

    public function __construct(
        private readonly LodestoneHttpClient $httpClient,
        private readonly LodestoneAchievementParser $achievementParser,
    ) {}

    /**
     * @return array{clears:int, data_source:string, bosses:array<int, array{key:string, kills:int, progress:int}>}
     */
    public function fetchForCharacter(Character $character, bool $ignoreCache = false): array
    {
        if ($ignoreCache) {
            $progress = $this->fetchFresh($character);
            $this->cache($character, $progress);

            return $progress;
        }

        $cacheTtl = (int) config('lodestone.cache_ttl', 300);

        if ($cacheTtl <= 0) {
            return $this->fetchFresh($character);
        }

        return Cache::remember(
            $this->cacheKey($character),
            $cacheTtl,
            fn () => $this->fetchFresh($character),
        );
    }

    /**
     * @return array{clears:int, data_source:string, bosses:array<int, array{key:string, kills:int, progress:int}>}
     */
    private function fetchFresh(Character $character): array
    {
        foreach (self::ACHIEVEMENT_CLEAR_FLOORS as $achievementId => $clearCountFloor) {
            $html = $this->httpClient->fetch($this->achievementUrl($character, $achievementId));

            if ($this->achievementParser->obtainedDate($html) === null) {
                continue;
            }

            return $this->progressFromClearFloor($clearCountFloor);
        }

        return $this->emptyProgress();
    }

    /**
     * @return array{clears:int, data_source:string, bosses:array<int, array{key:string, kills:int, progress:int}>}
     */
    private function progressFromClearFloor(int $clearCountFloor): array
    {
        return [
            'clears' => $clearCountFloor,
            'data_source' => OccultProgress::DATA_SOURCE_LODESTONE_ACHIEVEMENT,
            'bosses' => collect(['demon_tablet', 'dead_stars', 'marble_dragon', 'magitaur'])
                ->map(fn (string $key) => [
                    'key' => $key,
                    'kills' => $clearCountFloor,
                    'progress' => 100,
                ])
                ->all(),
        ];
    }

    /**
     * @return array{clears:int, data_source:string, bosses:array<int, array{key:string, kills:int, progress:int}>}
     */
    private function emptyProgress(): array
    {
        return [
            'clears' => 0,
            'data_source' => OccultProgress::DATA_SOURCE_LODESTONE_ACHIEVEMENT,
            'bosses' => collect(['demon_tablet', 'dead_stars', 'marble_dragon', 'magitaur'])
                ->map(fn (string $key) => [
                    'key' => $key,
                    'kills' => 0,
                    'progress' => 0,
                ])
                ->all(),
        ];
    }

    private function achievementUrl(Character $character, int $achievementId): string
    {
        $baseUrl = rtrim((string) config('lodestone.base_url'), '/');

        return "{$baseUrl}/character/{$character->lodestone_id}/achievement/detail/{$achievementId}/";
    }

    /**
     * @param  array{clears:int, data_source:string, bosses:array<int, array{key:string, kills:int, progress:int}>}  $progress
     */
    private function cache(Character $character, array $progress): void
    {
        $cacheTtl = (int) config('lodestone.cache_ttl', 300);

        if ($cacheTtl <= 0) {
            return;
        }

        Cache::put($this->cacheKey($character), $progress, $cacheTtl);
    }

    private function cacheKey(Character $character): string
    {
        return "lodestone:forked-tower-blood-achievements:{$character->lodestone_id}";
    }
}
