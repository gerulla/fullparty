<?php

namespace App\Services\Characters;

use App\DTOs\CharacterOnboardingResult;
use App\DTOs\LodestoneCharacterSearchResult;
use App\DTOs\QuotaCheck;
use App\Models\Character;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Lodestone\LodestoneCharacterSearchService;
use App\Services\Lodestone\LodestoneScraper;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Services\Quotas\QuotaService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Quotas\QuotaKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class CharacterOnboardingService
{
    public function __construct(
        private readonly LodestoneScraper $scraper,
        private readonly XIVAuthCharacterDirectory $directory,
        private readonly XIVAuthCharacterSyncService $xivAuthSync,
        private readonly CharacterProfileRefreshService $profiles,
        private readonly QuotaService $quotas,
        private readonly AuditLogger $audit,
        private readonly AccountCharacterNotificationService $notifications,
        private readonly LodestoneCharacterSearchService $search,
    ) {}

    /** @return array<int, array<string, string|null>> */
    public function searchCandidates(User $user, string $name, string $world): array
    {
        $results = $this->search->search($name, $world, PHP_INT_MAX);
        // Include unverified links too, and filter after the shared Lodestone cache.
        $linkedIds = Character::query()
            ->where('user_id', $user->id)
            ->pluck('lodestone_id')
            ->flip();

        return collect($results)
            ->reject(fn (LodestoneCharacterSearchResult $character) => $linkedIds->has($character->lodestoneId))
            ->take(20)
            ->map(fn (LodestoneCharacterSearchResult $character) => $character->toArray())
            ->values()
            ->all();
    }

    public function add(User $user, string $method, string $lodestoneId): CharacterOnboardingResult
    {
        $lock = Cache::lock('character-onboarding:'.$lodestoneId, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['onboarding' => 'busy']);
        }
        try {
            $existing = Character::where('lodestone_id', $lodestoneId)->first();
            if ($existing && (int) $existing->user_id === (int) $user->id
                && ($method === 'lodestone' || $existing->isClaimed())) {
                return new CharacterOnboardingResult($existing);
            }

            if ($method === 'xivauth') {
                // Never accept ownership claims or character metadata from the browser.
                $payload = collect($this->directory->forUser($user))->firstWhere('lodestone_id', $lodestoneId);
                if (! $payload) {
                    throw ValidationException::withMessages(['onboarding' => 'xivauth_character_unavailable']);
                }
                $result = $this->xivAuthSync->importOne($user, $payload, refreshAfterSync: false, transferUnclaimed: true);
                if ($result->hasConflicts() || ! $result->firstCharacter()) {
                    $existing = Character::where('lodestone_id', $lodestoneId)->firstOrFail();

                    return new CharacterOnboardingResult($existing, 'already_claimed');
                }

                return new CharacterOnboardingResult($result->firstCharacter(), transferred: $result->transferred);
            }
            if ($existing?->user_id !== null && (int) $existing->user_id !== (int) $user->id) {
                return new CharacterOnboardingResult($existing, $existing->isClaimed() ? 'already_claimed' : 'claim_required');
            }

            $data = $this->scraper->scrapeProfile($lodestoneId);
            $locked = null;

            $character = $this->quotas->runIf([new QuotaCheck(QuotaKey::CHARACTERS_TOTAL, $user)],
                function () use ($lodestoneId, &$locked): bool {
                    $locked = Character::where('lodestone_id', $lodestoneId)->lockForUpdate()->first();

                    return ! $locked || $locked->user_id === null;
                },
                function () use ($data, $user, &$locked): Character {
                    if ($locked && $locked->user_id !== null) {
                        return $locked;
                    }
                    $character = $locked ?? new Character(['lodestone_id' => $data->lodestoneId]);
                    $character->fill([
                        'user_id' => $user->id, 'name' => $data->name, 'world' => $data->world,
                        'datacenter' => $data->dataCenter, 'avatar_url' => $data->avatarUrl,
                        'add_method' => 'manual', 'verified_at' => null, 'claimed_at' => null,
                        'token' => null, 'expires_at' => null, 'is_primary' => false,
                    ])->save();
                    // A search is not proof for claiming existing guest applications.
                    $this->audit->log(
                        action: 'character.added', severity: AuditSeverity::INFO,
                        scopeType: AuditScope::CHARACTER, scopeId: $character->id,
                        message: 'audit_log.events.character.added', actor: $user, subject: $character,
                        metadata: ['method' => 'lodestone_search', 'lodestone_id' => $data->lodestoneId],
                    );
                    $this->notifications->notifyCharacterAdded($character, 'manual', $user);

                    return $character;
                });

            return new CharacterOnboardingResult($character, (int) $character->user_id === (int) $user->id
                ? 'added' : ($character->isClaimed() ? 'already_claimed' : 'claim_required'));
        } catch (UniqueConstraintViolationException) {
            // Also protect against a simultaneous add through a legacy character workflow.
            throw ValidationException::withMessages(['onboarding' => 'character_taken']);
        } finally {
            $lock->release();
        }
    }

    /** @return array{character: array<string, mixed>, partial: bool} */
    public function refresh(Character $character, ?\Closure $onProgress = null): array
    {
        $lock = Cache::lock('character-onboarding-refresh:'.$character->id, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['onboarding' => 'busy']);
        }
        try {
            $result = $onProgress
                ? $this->profiles->refresh($character, ignoreCache: true, onProgress: $onProgress)
                : $this->profiles->refresh($character, ignoreCache: true);

            return ['character' => $this->summary($character->fresh()), 'partial' => filled($result['fflogs_error'] ?? null)];
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    public function summary(Character $character): array
    {
        return [
            'id' => $character->id, 'lodestone_id' => $character->lodestone_id,
            'name' => $character->name, 'world' => $character->world,
            'datacenter' => $character->datacenter, 'avatar_url' => $character->avatar_url,
            'status' => $character->isClaimed() ? 'claimed' : ($character->isVerified() ? 'verified' : 'unverified'),
        ];
    }
}
