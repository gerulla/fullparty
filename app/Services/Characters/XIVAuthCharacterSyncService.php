<?php

namespace App\Services\Characters;

use App\DTOs\QuotaCheck;
use App\Models\ActivityApplication;
use App\Models\Character;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Services\Quotas\QuotaService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Input\TextInputSanitizer;
use App\Support\Quotas\QuotaKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class XIVAuthCharacterSyncService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CharacterProfileRefreshService $characterProfileRefreshService,
        private readonly AccountCharacterNotificationService $accountCharacterNotificationService,
        private readonly TextInputSanitizer $textInputSanitizer,
        private readonly QuotaService $quotaService,
    ) {}

    /**
     * @param  iterable<int, mixed>|mixed  $characters
     */
    public function syncMany(User $user, mixed $characters): XIVAuthCharacterSyncResult
    {
        $syncedCharacters = [];
        $conflicts = [];
        $createdCount = 0;
        $updatedCount = 0;

        foreach ($this->normalizeCharacterList($characters) as $payload) {
            $result = $this->syncOne(
                user: $user,
                payload: $payload,
                refreshAfterSync: false,
                notifyExistingVerified: false,
            );

            if ($result['conflict'] !== null) {
                $conflicts[] = $result['conflict'];

                continue;
            }

            if ($result['character'] instanceof Character) {
                $syncedCharacters[] = $result['character'];
            }

            $createdCount += $result['created'] ? 1 : 0;
            $updatedCount += $result['updated'] ? 1 : 0;
        }

        return new XIVAuthCharacterSyncResult(
            characters: $syncedCharacters,
            conflicts: $conflicts,
            createdCount: $createdCount,
            updatedCount: $updatedCount,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function importOne(User $user, array $payload, bool $refreshAfterSync = true): XIVAuthCharacterSyncResult
    {
        $normalized = $this->normalizeCharacterPayload($payload);

        if ($normalized === null) {
            return new XIVAuthCharacterSyncResult;
        }

        $result = $this->syncOne(
            user: $user,
            payload: $normalized,
            refreshAfterSync: $refreshAfterSync,
            notifyExistingVerified: true,
        );

        return new XIVAuthCharacterSyncResult(
            characters: $result['character'] instanceof Character ? [$result['character']] : [],
            conflicts: $result['conflict'] !== null ? [$result['conflict']] : [],
            createdCount: $result['created'] ? 1 : 0,
            updatedCount: $result['updated'] ? 1 : 0,
        );
    }

    /**
     * @param  array{name: string, world: string, datacenter: string, lodestone_id: string, avatar_url: string|null}  $payload
     * @return array{character: Character|null, conflict: array{name: string, lodestone_id: string}|null, created: bool, updated: bool}
     */
    private function syncOne(
        User $user,
        array $payload,
        bool $refreshAfterSync,
        bool $notifyExistingVerified,
    ): array {
        $operation = function () use ($user, $payload): array {
            $character = Character::query()
                ->where('lodestone_id', $payload['lodestone_id'])
                ->lockForUpdate()
                ->first();

            if (
                $character instanceof Character
                && $character->user_id !== null
                && (int) $character->user_id !== (int) $user->id
            ) {
                Log::warning('XIVAuth character sync skipped because the character is already claimed.', [
                    'user_id' => $user->id,
                    'existing_user_id' => $character->user_id,
                    'character_id' => $character->id,
                    'lodestone_id' => $character->lodestone_id,
                ]);

                return [
                    'character' => null,
                    'conflict' => $this->conflictPayload($character, $payload),
                    'created' => false,
                    'updated' => false,
                    'should_announce' => false,
                ];
            }

            $created = ! $character instanceof Character;
            $wasVerified = $character?->isVerified() ?? false;
            $hasPrimaryCharacter = $user->characters()
                ->where('is_primary', true)
                ->when($character instanceof Character, fn ($query) => $query->whereKeyNot($character->id))
                ->exists();

            if (! $character instanceof Character) {
                $character = new Character([
                    'lodestone_id' => $payload['lodestone_id'],
                ]);
            }

            $character->fill([
                'name' => $payload['name'],
                'world' => $payload['world'],
                'datacenter' => $payload['datacenter'],
                'avatar_url' => $payload['avatar_url'],
                'add_method' => 'xivauth',
            ]);

            $character->user_id = $user->id;
            $character->verified_at ??= Carbon::now();
            $character->token = null;
            $character->expires_at = null;

            if (! $hasPrimaryCharacter) {
                $otherCharacters = Character::query()
                    ->where('user_id', $user->id);

                if ($character->exists) {
                    $otherCharacters->whereKeyNot($character->id);
                }

                $otherCharacters->update(['is_primary' => false]);

                $character->is_primary = true;
            }

            $character->save();

            $this->claimCharacterApplications($character, $user);

            return [
                'character' => $character->fresh(),
                'conflict' => null,
                'created' => $created,
                'updated' => ! $created,
                'should_announce' => $created || ! $wasVerified,
            ];
        };

        $existingCharacter = Character::query()
            ->where('lodestone_id', $payload['lodestone_id'])
            ->first();
        $needsCapacity = ! $existingCharacter instanceof Character
            || $existingCharacter->user_id === null;

        $sync = $needsCapacity
            ? $this->quotaService->run([
                new QuotaCheck(QuotaKey::CHARACTERS_TOTAL, $user),
            ], $operation)
            : DB::transaction($operation);

        $character = $sync['character'];

        if (! $character instanceof Character) {
            return [
                'character' => null,
                'conflict' => $sync['conflict'],
                'created' => false,
                'updated' => false,
            ];
        }

        if ($refreshAfterSync) {
            $this->refreshVerifiedCharacterOnce($character);
            $character->refresh();
        }

        if ($sync['should_announce'] || $notifyExistingVerified) {
            $this->accountCharacterNotificationService->notifyCharacterAdded($character, 'xivauth', $user);

            $this->auditLogger->log(
                action: 'character.verified',
                severity: AuditSeverity::INFO,
                scopeType: AuditScope::CHARACTER,
                scopeId: $character->id,
                message: 'audit_log.events.character.verified',
                actor: $user,
                subject: $character,
                metadata: [
                    'verification_method' => 'xivauth',
                    'lodestone_id' => $character->lodestone_id,
                    'is_primary' => $character->is_primary,
                ],
            );
        }

        return [
            'character' => $character,
            'conflict' => null,
            'created' => $sync['created'],
            'updated' => $sync['updated'],
        ];
    }

    private function claimCharacterApplications(Character $character, User $user): void
    {
        $applications = ActivityApplication::query()
            ->whereNull('user_id')
            ->where('applicant_lodestone_id', $character->lodestone_id)
            ->lockForUpdate()
            ->get();

        if ($applications->isEmpty()) {
            return;
        }

        $conflictingActivityIds = ActivityApplication::query()
            ->where('user_id', $user->id)
            ->whereIn('activity_id', $applications->pluck('activity_id'))
            ->pluck('activity_id')
            ->all();

        foreach ($applications as $application) {
            if (in_array($application->activity_id, $conflictingActivityIds, true)) {
                Log::warning('Skipping guest application claim because the user already has an application for the activity.', [
                    'application_id' => $application->id,
                    'activity_id' => $application->activity_id,
                    'user_id' => $user->id,
                    'character_id' => $character->id,
                    'lodestone_id' => $character->lodestone_id,
                ]);

                continue;
            }

            $application->update([
                'user_id' => $user->id,
                'selected_character_id' => $character->id,
                'guest_access_token' => null,
            ]);
        }
    }

    private function refreshVerifiedCharacterOnce(Character $character): void
    {
        try {
            $this->characterProfileRefreshService->refresh($character, ignoreCache: true);
        } catch (\Throwable $exception) {
            Log::warning('Unable to auto-refresh character data after XIVAuth verification.', [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, array{name: string, world: string, datacenter: string, lodestone_id: string, avatar_url: string|null}>
     */
    private function normalizeCharacterList(mixed $characters): array
    {
        if (! is_iterable($characters)) {
            return [];
        }

        $normalized = [];

        foreach ($characters as $character) {
            $payload = $this->normalizeCharacterPayload($character);

            if ($payload === null) {
                continue;
            }

            $normalized[$payload['lodestone_id']] = $payload;
        }

        return array_values($normalized);
    }

    /**
     * @return array{name: string, world: string, datacenter: string, lodestone_id: string, avatar_url: string|null}|null
     */
    private function normalizeCharacterPayload(mixed $character): ?array
    {
        if (is_object($character)) {
            $character = (array) $character;
        }

        if (! is_array($character)) {
            return null;
        }

        $payload = [
            'name' => $this->sanitizeSingleLine(data_get($character, 'name')),
            'world' => $this->sanitizeSingleLine(data_get($character, 'home_world') ?? data_get($character, 'homeWorld') ?? data_get($character, 'world')),
            'datacenter' => $this->sanitizeSingleLine(data_get($character, 'data_center') ?? data_get($character, 'dataCenter') ?? data_get($character, 'datacenter')),
            'lodestone_id' => $this->sanitizeSingleLine(data_get($character, 'lodestone_id') ?? data_get($character, 'lodestoneId')),
            'avatar_url' => data_get($character, 'avatar_url') ?? data_get($character, 'avatarUrl'),
        ];

        if (
            blank($payload['name'])
            || blank($payload['world'])
            || blank($payload['datacenter'])
            || blank($payload['lodestone_id'])
        ) {
            return null;
        }

        if (! in_array($payload['datacenter'], config('datacenters.values', []), true)) {
            Log::warning('XIVAuth character sync skipped a character with an unknown data center.', [
                'lodestone_id' => $payload['lodestone_id'],
                'datacenter' => $payload['datacenter'],
            ]);

            return null;
        }

        $avatarUrl = is_string($payload['avatar_url']) && trim($payload['avatar_url']) !== ''
            ? trim($payload['avatar_url'])
            : null;

        return [
            'name' => mb_substr($payload['name'], 0, 255),
            'world' => mb_substr($payload['world'], 0, 255),
            'datacenter' => mb_substr($payload['datacenter'], 0, 255),
            'lodestone_id' => mb_substr($payload['lodestone_id'], 0, 255),
            'avatar_url' => $avatarUrl ? mb_substr($avatarUrl, 0, 500) : null,
        ];
    }

    private function sanitizeSingleLine(mixed $value): string
    {
        return $this->textInputSanitizer->sanitizeSingleLine(is_scalar($value) ? (string) $value : null) ?? '';
    }

    /**
     * @param  array{name: string, world: string, datacenter: string, lodestone_id: string, avatar_url: string|null}  $payload
     * @return array{name: string, lodestone_id: string}
     */
    private function conflictPayload(Character $character, array $payload): array
    {
        return [
            'name' => $payload['name'] !== '' ? $payload['name'] : $character->name,
            'lodestone_id' => $payload['lodestone_id'],
        ];
    }
}
