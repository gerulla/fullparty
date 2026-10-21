<?php

namespace App\Services\Characters;

use App\DTOs\QuotaCheck;
use App\Models\Character;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Lodestone\LodestoneScraper;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Services\Quotas\QuotaService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Quotas\QuotaKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LodestoneCharacterClaimService
{
    public function __construct(
        private readonly LodestoneScraper $scraper,
        private readonly CharacterApplicationClaimService $applications,
        private readonly CharacterProfileRefreshService $refresh,
        private readonly AuditLogger $audit,
        private readonly AccountCharacterNotificationService $notifications,
        private readonly CharacterOwnershipTransferService $transfers,
        private readonly QuotaService $quotas,
    ) {}

    /** Each requesting account gets independent proof; the current owner's token is never exposed or replaced. */
    public function issueTransferCode(User $user, Character $character): array
    {
        return DB::transaction(function () use ($user, $character): array {
            $character = Character::query()->lockForUpdate()->findOrFail($character->id);
            $this->ensureTransferable($user, $character);
            $key = $this->transferCodeKey($user, $character);
            $challenge = Cache::get($key);
            if (! is_array($challenge) || ($challenge['owner_id'] ?? null) !== $character->user_id
                || ($challenge['expires_at'] ?? 0) <= now()->timestamp) {
                $challenge = [
                    'code' => 'FP-'.strtoupper(Str::random(20)),
                    'owner_id' => $character->user_id,
                    'expires_at' => now()->addDay()->timestamp,
                ];
                Cache::put($key, $challenge, now()->addDay());
            }

            return [
                'code' => $challenge['code'],
                'expires_at' => now()->setTimestamp($challenge['expires_at'])->toIso8601String(),
            ];
        });
    }

    public function verifyTransfer(User $user, Character $character, string $code): Character
    {
        $character->refresh();
        $this->ensureTransferable($user, $character);
        $this->validateTransferCode($user, $character, $code);
        $profile = $this->scraper->scrapeProfile($character->lodestone_id, ignoreCache: true);
        if ((string) $profile->lodestoneId !== (string) $character->lodestone_id
            || ! preg_match('/(?<![A-Za-z0-9-])'.preg_quote($code, '/').'(?![A-Za-z0-9-])/', $profile->bio ?? '')) {
            throw ValidationException::withMessages(['claim' => 'code_not_found']);
        }

        $locked = null;
        $character = DB::transaction(fn () => $this->quotas->runIf(
            [new QuotaCheck(QuotaKey::CHARACTERS_TOTAL, $user)],
            function () use ($user, $character, $code, &$locked): bool {
                $locked = Character::query()->lockForUpdate()->findOrFail($character->id);
                $this->ensureTransferable($user, $locked);
                $this->validateTransferCode($user, $locked, $code);

                return true;
            },
            function () use ($user, &$locked): Character {
                $character = $this->transfers->transfer($locked, $user, 'lodestone_token');
                $this->notifications->notifyCharacterAdded($character, 'lodestone_token', $user);

                return $character;
            },
        ), 3);
        Cache::forget($this->transferCodeKey($user, $character));

        return $character;
    }

    private function ensureTransferable(User $user, Character $character): void
    {
        abort_unless($character->user_id !== null && (int) $character->user_id !== (int) $user->id, 404);
        if ($character->isClaimed()) {
            throw ValidationException::withMessages(['claim' => 'already_claimed']);
        }
    }

    private function transferCodeKey(User $user, Character $character): string
    {
        return 'character-transfer-code:'.$user->id.':'.$character->id;
    }

    private function validateTransferCode(User $user, Character $character, string $code): void
    {
        $challenge = Cache::get($this->transferCodeKey($user, $character));
        if (! is_array($challenge) || ($challenge['expires_at'] ?? 0) <= now()->timestamp) {
            throw ValidationException::withMessages(['claim' => 'expired_code']);
        }
        if (($challenge['owner_id'] ?? null) !== $character->user_id) {
            throw ValidationException::withMessages(['claim' => 'ownership_changed']);
        }
        if (! hash_equals($challenge['code'], $code)) {
            throw ValidationException::withMessages(['claim' => 'code_changed']);
        }
    }

    public function issueCode(User $user, Character $character): Character
    {
        return DB::transaction(function () use ($user, $character): Character {
            $character = Character::query()->lockForUpdate()->findOrFail($character->id);
            $this->authorizeOwner($user, $character);

            if (! $character->isClaimed() && (blank($character->token) || ! $character->expires_at || $character->isTokenExpired())) {
                $character->update([
                    'token' => 'FP-'.strtoupper(Str::random(20)),
                    'expires_at' => now()->addDay(),
                ]);
            }

            return $character;
        });
    }

    public function verify(User $user, Character $character, string $code, bool $refreshAfterClaim = false): Character
    {
        $character->refresh();
        $this->authorizeOwner($user, $character);
        if ($character->isClaimed()) {
            return $character;
        }
        $this->validateCode($character, $code);

        // Proof must come from the current public biography, never a cached profile.
        $profile = $this->scraper->scrapeProfile($character->lodestone_id, ignoreCache: true);
        if ((string) $profile->lodestoneId !== (string) $character->lodestone_id
            || ! preg_match('/(?<![A-Za-z0-9-])'.preg_quote($code, '/').'(?![A-Za-z0-9-])/', $profile->bio ?? '')) {
            throw ValidationException::withMessages(['claim' => 'code_not_found']);
        }

        $newlyClaimed = false;
        $character = DB::transaction(function () use ($user, $character, $code, &$newlyClaimed): Character {
            // Serialize primary-character selection and concurrent claims for this account.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked = Character::query()->lockForUpdate()->findOrFail($character->id);
            $this->authorizeOwner($user, $locked);
            if ($locked->isClaimed()) {
                return $locked;
            }
            // The code or owner may have changed while the external request was in flight.
            $this->validateCode($locked, $code);
            $locked->verified_at ??= now();
            $locked->claimed_at = now();
            $locked->token = null;
            $locked->expires_at = null;
            $locked->add_method = 'manual';
            $locked->is_primary = $locked->is_primary || ! Character::query()
                ->where('user_id', $user->id)->where('is_primary', true)->whereKeyNot($locked->id)->exists();
            $locked->save();
            $this->applications->claim($locked, $user);
            $this->audit->log(
                action: 'character.verified', severity: AuditSeverity::INFO,
                scopeType: AuditScope::CHARACTER, scopeId: $locked->id,
                message: 'audit_log.events.character.verified', actor: $user, subject: $locked,
                metadata: ['verification_method' => 'lodestone_token', 'lodestone_id' => $locked->lodestone_id, 'is_primary' => $locked->is_primary],
            );
            $newlyClaimed = true;

            return $locked;
        });

        if ($newlyClaimed) {
            if ($refreshAfterClaim) {
                try {
                    $this->refresh->refresh($character, ignoreCache: true);
                } catch (\Throwable $exception) {
                    Log::warning('Unable to auto-refresh character data after verification.', [
                        'character_id' => $character->id, 'lodestone_id' => $character->lodestone_id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
            $this->notifications->notifyCharacterAdded($character->fresh(), 'lodestone_token', $user);
        }

        return $character->fresh();
    }

    private function authorizeOwner(User $user, Character $character): void
    {
        abort_unless((int) $character->user_id === (int) $user->id, 404);
    }

    private function validateCode(Character $character, string $code): void
    {
        if (blank($character->token) || ! $character->expires_at || $character->isTokenExpired()) {
            throw ValidationException::withMessages(['claim' => 'expired_code']);
        }
        if (! hash_equals($character->token, $code)) {
            throw ValidationException::withMessages(['claim' => 'code_changed']);
        }
    }
}
