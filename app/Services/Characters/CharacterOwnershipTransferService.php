<?php

namespace App\Services\Characters;

use App\Models\Character;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Validation\ValidationException;

final class CharacterOwnershipTransferService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountCharacterNotificationService $notifications,
        private readonly CharacterTransferCompletionService $completion,
    ) {}

    /** Caller must hold the character and receiving account locks and have verified fresh ownership proof. */
    public function transfer(Character $character, User $recipient, string $method): Character
    {
        if ($character->isClaimed()) {
            throw ValidationException::withMessages(['claim' => 'already_claimed']);
        }
        $previousOwner = User::query()->lockForUpdate()->findOrFail($character->user_id);
        $wasPrimary = (bool) $character->is_primary;
        $previousStatus = $character->isVerified() ? 'verified' : 'unverified';
        $this->completion->begin($character, $recipient, $method);
        $character->fill([
            'user_id' => $recipient->id,
            'is_primary' => ! $recipient->characters()->where('is_primary', true)->exists(),
            'verified_at' => now(),
            'claimed_at' => now(),
            'add_method' => $method === 'xivauth' ? 'xivauth' : 'manual',
            'token' => null,
            'expires_at' => null,
        ])->save();

        if ($wasPrimary && ! $previousOwner->characters()->where('is_primary', true)->exists()) {
            $replacement = $previousOwner->characters()->orderByDesc('verified_at')->orderBy('id')->lockForUpdate()->first();
            $replacement?->update(['is_primary' => true]);
        }

        $this->audit->log(
            action: 'character.transferred', severity: AuditSeverity::SEVERE_CHANGE,
            scopeType: AuditScope::CHARACTER, scopeId: $character->id,
            message: 'audit_log.events.character.transferred', actor: $recipient, subject: $character,
            metadata: [
                'character_name' => $character->name, 'lodestone_id' => $character->lodestone_id,
                'verification_method' => $method, 'previous_status' => $previousStatus,
                'from_user_id' => $previousOwner->id, 'from_user_name' => $previousOwner->name,
                'to_user_id' => $recipient->id, 'to_user_name' => $recipient->name,
            ],
        );
        $character = $character->fresh();
        $this->notifications->notifyCharacterUnclaimed($character, $previousOwner, $recipient);

        return $character;
    }
}
