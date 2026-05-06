<?php

namespace App\Services\Characters;

use App\Exceptions\LodestoneFetchException;
use App\Exceptions\LodestoneInvalidInputException;
use App\Exceptions\LodestoneParseException;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Services\FFLogs\ForkedTowerBloodProgressFetcher;
use App\Services\Lodestone\LodestoneScraper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CharacterProfileRefreshService
{
    public function __construct(
        private readonly LodestoneScraper $scraper,
        private readonly ForkedTowerBloodProgressFetcher $forkedTowerBloodProgressFetcher,
    ) {}

    /**
     * @throws LodestoneInvalidInputException
     * @throws LodestoneFetchException
     * @throws LodestoneParseException
     */
    public function refresh(Character $character, bool $ignoreCache = true): void
    {
        $data = $this->scraper->scrape($character->lodestone_id, ignoreCache: $ignoreCache);
        $forkedTowerBloodProgress = $this->fetchForkedTowerBloodProgress($character);

        DB::transaction(function () use ($character, $data, $forkedTowerBloodProgress): void {
            $character->update([
                'name' => $data->name,
                'world' => $data->world,
                'datacenter' => $data->dataCenter,
                'avatar_url' => $data->avatarUrl,
            ]);

            $this->syncCharacterClassLevels($character, $data->extraData);
            $this->syncPhantomJobLevels($character, $data->extraData);
            $this->syncOccultProgress($character, $data->extraData, $forkedTowerBloodProgress);
        });
    }

    private function fetchForkedTowerBloodProgress(Character $character): array
    {
        try {
            return $this->forkedTowerBloodProgressFetcher->fetchForCharacter($character);
        } catch (\Throwable $exception) {
            Log::warning('Unable to refresh FF Logs progress during character refresh.', [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->emptyForkedTowerBloodProgress();
        }
    }

    private function syncCharacterClassLevels(Character $character, array $extraData): void
    {
        $existingProgress = $character->classes()
            ->get()
            ->keyBy('id');

        $syncPayload = CharacterClass::query()
            ->get()
            ->mapWithKeys(function (CharacterClass $characterClass) use ($existingProgress, $extraData) {
                $existing = $existingProgress->get($characterClass->id);
                $level = (int) ($extraData[sprintf('job.%s.level', strtolower($characterClass->shorthand))] ?? 0);

                return [
                    $characterClass->id => [
                        'level' => $level,
                        'is_preferred' => $existing?->pivot?->is_preferred ?? false,
                    ],
                ];
            })
            ->all();

        $character->classes()->sync($syncPayload);
    }

    private function syncPhantomJobLevels(Character $character, array $extraData): void
    {
        $existingProgress = $character->phantomJobs()
            ->get()
            ->keyBy('id');

        $syncPayload = PhantomJob::query()
            ->get()
            ->mapWithKeys(function (PhantomJob $phantomJob) use ($existingProgress, $extraData) {
                $existing = $existingProgress->get($phantomJob->id);
                $currentLevel = (int) ($extraData[sprintf('phantom.%s.level', $this->normalizeOccultSlug($phantomJob->name))] ?? 0);

                return [
                    $phantomJob->id => [
                        'current_level' => $currentLevel,
                        'is_preferred' => $existing?->pivot?->is_preferred ?? false,
                    ],
                ];
            })
            ->all();

        $character->phantomJobs()->sync($syncPayload);
    }

    private function syncOccultProgress(Character $character, array $extraData, array $forkedTowerBloodProgress): void
    {
        $bosses = collect($forkedTowerBloodProgress['bosses'] ?? [])->keyBy('key');

        $character->occultProgress()->updateOrCreate(
            ['character_id' => $character->id],
            [
                'knowledge_level' => (int) ($extraData['progression.occult.knowledge_level'] ?? 0),
                'demon_tablet_kills' => (int) ($bosses->get('demon_tablet')['kills'] ?? 0),
                'demon_tablet_progress' => (int) ($bosses->get('demon_tablet')['progress'] ?? 0),
                'dead_stars_kills' => (int) ($bosses->get('dead_stars')['kills'] ?? 0),
                'dead_stars_progress' => (int) ($bosses->get('dead_stars')['progress'] ?? 0),
                'marble_dragon_kills' => (int) ($bosses->get('marble_dragon')['kills'] ?? 0),
                'marble_dragon_progress' => (int) ($bosses->get('marble_dragon')['progress'] ?? 0),
                'magitaur_kills' => (int) ($bosses->get('magitaur')['kills'] ?? 0),
                'magitaur_progress' => (int) ($bosses->get('magitaur')['progress'] ?? 0),
            ]
        );
    }

    private function emptyForkedTowerBloodProgress(): array
    {
        return [
            'clears' => 0,
            'bosses' => [
                ['key' => 'demon_tablet', 'kills' => 0, 'progress' => 0],
                ['key' => 'dead_stars', 'kills' => 0, 'progress' => 0],
                ['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
                ['key' => 'magitaur', 'kills' => 0, 'progress' => 0],
            ],
        ];
    }

    private function normalizeOccultSlug(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/^phantom\s+/i', '', $normalized);

        return str_replace(' ', '_', $normalized);
    }
}
