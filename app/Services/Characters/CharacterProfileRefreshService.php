<?php

namespace App\Services\Characters;

use App\Exceptions\LodestoneFetchException;
use App\Exceptions\LodestoneInvalidInputException;
use App\Exceptions\LodestoneParseException;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\OccultProgress;
use App\Models\PhantomJob;
use App\Services\FFLogs\ForkedTowerBloodProgressFetcher;
use App\Services\FFLogs\ForkedTowerMagicProgressFetcher;
use App\Services\Lodestone\ForkedTowerBloodAchievementProgressFetcher;
use App\Services\Lodestone\ForkedTowerMagicAchievementProgressFetcher;
use App\Services\Lodestone\LodestoneScraper;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CharacterProfileRefreshService
{
    public function __construct(
        private readonly LodestoneScraper $scraper,
        private readonly ForkedTowerBloodProgressFetcher $forkedTowerBloodProgressFetcher,
        private readonly ForkedTowerMagicProgressFetcher $forkedTowerMagicProgressFetcher,
        private readonly ForkedTowerBloodAchievementProgressFetcher $forkedTowerBloodAchievementProgressFetcher,
        private readonly ForkedTowerMagicAchievementProgressFetcher $forkedTowerMagicAchievementProgressFetcher,
    ) {}

    /**
     * @return array{fflogs_error: array<string, mixed>|null}
     *
     * @throws LodestoneInvalidInputException
     * @throws LodestoneFetchException
     * @throws LodestoneParseException
     */
    public function refresh(Character $character, bool $ignoreCache = true): array
    {
        $data = $this->scraper->scrape($character->lodestone_id, ignoreCache: $ignoreCache);
        $refreshedAt = now();
        $character->forceFill([
            'name' => $data->name,
            'world' => $data->world,
            'datacenter' => $data->dataCenter,
            'avatar_url' => $data->avatarUrl,
            'lodestone_refreshed_at' => $refreshedAt,
        ]);

        $forkedTowerBloodProgressResult = $this->fetchForkedTowerBloodProgress($character, $ignoreCache);
        $forkedTowerBloodProgress = $forkedTowerBloodProgressResult['progress'];
        $forkedTowerMagicProgressResult = $this->fetchForkedTowerMagicProgress($character, $ignoreCache);
        $forkedTowerMagicProgress = $forkedTowerMagicProgressResult['progress'];

        DB::transaction(function () use ($character, $data, $forkedTowerBloodProgress, $forkedTowerMagicProgress, $refreshedAt): void {
            $character->update([
                'name' => $data->name,
                'world' => $data->world,
                'datacenter' => $data->dataCenter,
                'avatar_url' => $data->avatarUrl,
                'lodestone_refreshed_at' => $refreshedAt,
            ]);

            $this->syncCharacterClassLevels($character, $data->extraData);
            $this->syncPhantomJobLevels($character, $data->extraData);
            $this->syncOccultProgress($character, $data->extraData, $forkedTowerBloodProgress, $forkedTowerMagicProgress);
        });

        return [
            'fflogs_error' => $forkedTowerBloodProgressResult['error'] ?? $forkedTowerMagicProgressResult['error'],
        ];
    }

    /**
     * @return array{refreshed: bool, available_at: CarbonInterface|null, fflogs_error: array<string, mixed>|null}
     *
     * @throws LodestoneInvalidInputException
     * @throws LodestoneFetchException
     * @throws LodestoneParseException
     */
    public function refreshIfOlderThan(Character $character, int $cooldownSeconds): array
    {
        $availableAt = $this->refreshAvailableAt($character, $cooldownSeconds);

        if ($availableAt && $availableAt->isFuture()) {
            return [
                'refreshed' => false,
                'available_at' => $availableAt,
                'fflogs_error' => null,
            ];
        }

        $refreshResult = $this->refresh($character, ignoreCache: true);

        return [
            'refreshed' => true,
            'available_at' => $this->refreshAvailableAt($character->fresh(), $cooldownSeconds),
            'fflogs_error' => $refreshResult['fflogs_error'],
        ];
    }

    public function refreshAvailableAt(Character $character, int $cooldownSeconds): ?CarbonInterface
    {
        $lastCheckedAt = $character->lodestone_refreshed_at ?? $character->updated_at;

        return $lastCheckedAt?->copy()->addSeconds($cooldownSeconds);
    }

    /**
     * @return array{progress: array<string, mixed>, error: array<string, mixed>|null}
     */
    private function fetchForkedTowerBloodProgress(Character $character, bool $ignoreCache): array
    {
        try {
            $progress = $this->withForkedTowerDataSource(
                $this->forkedTowerBloodProgressFetcher->fetchForCharacter($character, ignoreCache: $ignoreCache),
                OccultProgress::DATA_SOURCE_FFLOGS,
            );

            if ($this->hasForkedTowerProgress($progress)) {
                return [
                    'progress' => $progress,
                    'error' => null,
                ];
            }

            $lodestoneAchievementProgress = $this->fetchForkedTowerBloodAchievementProgress($character, $ignoreCache);

            if ($this->hasForkedTowerProgress($lodestoneAchievementProgress)) {
                return [
                    'progress' => $lodestoneAchievementProgress,
                    'error' => null,
                ];
            }

            return [
                'progress' => $progress,
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Unable to refresh FF Logs progress during character refresh.', [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'exception' => $exception->getMessage(),
            ]);

            $fflogsError = [
                'source' => 'fflogs',
                'type' => $exception::class,
                'message' => $exception->getMessage(),
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'name' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
                'zone_id' => config('services.ff_logs.forked_tower_blood_zone_id'),
            ];

            $lodestoneAchievementProgress = $this->fetchForkedTowerBloodAchievementProgress($character, $ignoreCache);

            if ($this->hasForkedTowerProgress($lodestoneAchievementProgress)) {
                return [
                    'progress' => $lodestoneAchievementProgress,
                    'error' => $fflogsError,
                ];
            }

            return [
                'progress' => $this->emptyForkedTowerBloodProgress(),
                'error' => $fflogsError,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchForkedTowerBloodAchievementProgress(Character $character, bool $ignoreCache): array
    {
        try {
            return $this->forkedTowerBloodAchievementProgressFetcher->fetchForCharacter(
                $character,
                ignoreCache: $ignoreCache,
            );
        } catch (\Throwable $exception) {
            Log::warning('Unable to refresh Lodestone achievement progress during character refresh.', [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->withForkedTowerDataSource(
                $this->emptyForkedTowerBloodProgress(),
                OccultProgress::DATA_SOURCE_LODESTONE_ACHIEVEMENT,
            );
        }
    }

    /**
     * @return array{progress: array<string, mixed>|null, error: array<string, mixed>|null}
     */
    private function fetchForkedTowerMagicProgress(Character $character, bool $ignoreCache): array
    {
        try {
            $progress = $this->withForkedTowerDataSource(
                $this->forkedTowerMagicProgressFetcher->fetchForCharacter(
                    $character,
                    ignoreCache: $ignoreCache,
                ),
                OccultProgress::DATA_SOURCE_FFLOGS,
            );

            if ($this->hasForkedTowerProgress($progress)) {
                return [
                    'progress' => $progress,
                    'error' => null,
                ];
            }

            $lodestoneAchievementProgress = $this->fetchForkedTowerMagicAchievementProgress($character, $ignoreCache);

            if ($this->hasForkedTowerProgress($lodestoneAchievementProgress)) {
                return [
                    'progress' => $lodestoneAchievementProgress,
                    'error' => null,
                ];
            }

            return [
                'progress' => $progress,
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Unable to refresh Forked Tower: Magic (Extreme) FF Logs progress during character refresh.', [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'zone_id' => config('services.ff_logs.forked_tower_magic_zone_id'),
                'exception' => $exception->getMessage(),
            ]);

            $fflogsError = [
                'source' => 'fflogs',
                'type' => $exception::class,
                'message' => $exception->getMessage(),
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'name' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
                'zone_id' => config('services.ff_logs.forked_tower_magic_zone_id'),
            ];

            $lodestoneAchievementProgress = $this->fetchForkedTowerMagicAchievementProgress($character, $ignoreCache);

            if ($this->hasForkedTowerProgress($lodestoneAchievementProgress)) {
                return [
                    'progress' => $lodestoneAchievementProgress,
                    'error' => $fflogsError,
                ];
            }

            return [
                'progress' => null,
                'error' => $fflogsError,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchForkedTowerMagicAchievementProgress(Character $character, bool $ignoreCache): array
    {
        try {
            return $this->forkedTowerMagicAchievementProgressFetcher->fetchForCharacter(
                $character,
                ignoreCache: $ignoreCache,
            );
        } catch (\Throwable $exception) {
            Log::warning('Unable to refresh Forked Tower: Magic (Extreme) Lodestone achievement progress during character refresh.', [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->withForkedTowerDataSource(
                $this->emptyForkedTowerMagicProgress(),
                OccultProgress::DATA_SOURCE_LODESTONE_ACHIEVEMENT,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    private function withForkedTowerDataSource(array $progress, string $dataSource): array
    {
        $progress['data_source'] = $dataSource;

        return $progress;
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function hasForkedTowerProgress(array $progress): bool
    {
        if ((int) ($progress['clears'] ?? 0) > 0) {
            return true;
        }

        foreach ($progress['bosses'] ?? [] as $boss) {
            if ((int) ($boss['kills'] ?? 0) > 0 || (int) ($boss['progress'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
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

    private function syncOccultProgress(
        Character $character,
        array $extraData,
        array $forkedTowerBloodProgress,
        ?array $forkedTowerMagicProgress,
    ): void {
        $bloodBosses = collect($forkedTowerBloodProgress['bosses'] ?? [])->keyBy('key');
        $values = [
            'data_source' => (string) ($forkedTowerBloodProgress['data_source'] ?? OccultProgress::DATA_SOURCE_FFLOGS),
            'knowledge_level' => (int) ($extraData['progression.occult.knowledge_level'] ?? 0),
            'demon_tablet_kills' => (int) ($bloodBosses->get('demon_tablet')['kills'] ?? 0),
            'demon_tablet_progress' => (int) ($bloodBosses->get('demon_tablet')['progress'] ?? 0),
            'dead_stars_kills' => (int) ($bloodBosses->get('dead_stars')['kills'] ?? 0),
            'dead_stars_progress' => (int) ($bloodBosses->get('dead_stars')['progress'] ?? 0),
            'marble_dragon_kills' => (int) ($bloodBosses->get('marble_dragon')['kills'] ?? 0),
            'marble_dragon_progress' => (int) ($bloodBosses->get('marble_dragon')['progress'] ?? 0),
            'magitaur_kills' => (int) ($bloodBosses->get('magitaur')['kills'] ?? 0),
            'magitaur_progress' => (int) ($bloodBosses->get('magitaur')['progress'] ?? 0),
        ];

        if ($forkedTowerMagicProgress !== null) {
            $magicBosses = collect($forkedTowerMagicProgress['bosses'] ?? [])->keyBy('key');
            $values = array_merge($values, [
                'forked_tower_magic_data_source' => (string) ($forkedTowerMagicProgress['data_source'] ?? OccultProgress::DATA_SOURCE_FFLOGS),
                'two_headed_aevis_kills' => (int) ($magicBosses->get('two_headed_aevis')['kills'] ?? 0),
                'two_headed_aevis_progress' => (int) ($magicBosses->get('two_headed_aevis')['progress'] ?? 0),
                'sword_dancer_kills' => (int) ($magicBosses->get('sword_dancer')['kills'] ?? 0),
                'sword_dancer_progress' => (int) ($magicBosses->get('sword_dancer')['progress'] ?? 0),
                'necrophobia_kills' => (int) ($magicBosses->get('necrophobia')['kills'] ?? 0),
                'necrophobia_progress' => (int) ($magicBosses->get('necrophobia')['progress'] ?? 0),
                'index_kills' => (int) ($magicBosses->get('index')['kills'] ?? 0),
                'index_progress' => (int) ($magicBosses->get('index')['progress'] ?? 0),
            ]);
        }

        $character->occultProgress()->updateOrCreate(
            ['character_id' => $character->id],
            $values,
        );
    }

    private function emptyForkedTowerBloodProgress(): array
    {
        return [
            'clears' => 0,
            'data_source' => OccultProgress::DATA_SOURCE_FFLOGS,
            'bosses' => [
                ['key' => 'demon_tablet', 'kills' => 0, 'progress' => 0],
                ['key' => 'dead_stars', 'kills' => 0, 'progress' => 0],
                ['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
                ['key' => 'magitaur', 'kills' => 0, 'progress' => 0],
            ],
        ];
    }

    private function emptyForkedTowerMagicProgress(): array
    {
        return [
            'clears' => 0,
            'data_source' => OccultProgress::DATA_SOURCE_FFLOGS,
            'bosses' => [
                ['key' => 'two_headed_aevis', 'kills' => 0, 'progress' => 0],
                ['key' => 'sword_dancer', 'kills' => 0, 'progress' => 0],
                ['key' => 'necrophobia', 'kills' => 0, 'progress' => 0],
                ['key' => 'index', 'kills' => 0, 'progress' => 0],
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
