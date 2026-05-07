<?php

namespace App\Services\Notifications;

use App\Models\Character;
use App\Models\User;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;

class AccountCharacterNotificationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function notifySocialAccountLinked(User $user, string $provider, mixed $actor = null): void
    {
        $this->dispatchAccountCharacterNotification(
            recipient: $user,
            type: 'user.social_account.linked',
            titleKey: 'notifications.user.social_account.linked.title',
            bodyKey: 'notifications.user.social_account.linked.body',
            messageParams: [
                'provider' => $this->providerLabel($provider),
            ],
            actionUrl: route('settings'),
            actor: $actor,
            subject: $user,
            payload: [
                'provider' => $provider,
            ],
            sendOffSite: true,
        );
    }

    public function notifySocialAccountUnlinked(User $user, string $provider, mixed $actor = null): void
    {
        $this->dispatchAccountCharacterNotification(
            recipient: $user,
            type: 'user.social_account.unlinked',
            titleKey: 'notifications.user.social_account.unlinked.title',
            bodyKey: 'notifications.user.social_account.unlinked.body',
            messageParams: [
                'provider' => $this->providerLabel($provider),
            ],
            actionUrl: route('settings'),
            actor: $actor,
            subject: $user,
            payload: [
                'provider' => $provider,
            ],
            sendOffSite: true,
        );
    }

    public function notifyCharacterAdded(Character $character, string $method, mixed $actor = null): void
    {
        $recipient = $this->characterRecipient($character);

        if (!$recipient) {
            return;
        }

        $this->dispatchAccountCharacterNotification(
            recipient: $recipient,
            type: 'characters.added',
            titleKey: 'notifications.characters.added.title',
            bodyKey: 'notifications.characters.added.body',
            messageParams: [
                'character' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
                'method' => $this->characterMethodLabel($method),
            ],
            actionUrl: route('account.characters'),
            actor: $actor,
            subject: $character,
            payload: [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'method' => $method,
            ],
            sendOffSite: true,
        );
    }

    public function notifyCharacterRefreshed(Character $character, mixed $actor = null): void
    {
        $recipient = $this->characterRecipient($character);

        if (!$recipient) {
            return;
        }

        $this->dispatchAccountCharacterNotification(
            recipient: $recipient,
            type: 'characters.refreshed',
            titleKey: 'notifications.characters.refreshed.title',
            bodyKey: 'notifications.characters.refreshed.body',
            messageParams: [
                'character' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
            ],
            actionUrl: route('account.characters'),
            actor: $actor,
            subject: $character,
            payload: [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
            ],
            sendOffSite: false,
        );
    }

    public function notifyPrimaryCharacterChanged(Character $character, mixed $actor = null): void
    {
        $recipient = $this->characterRecipient($character);

        if (!$recipient) {
            return;
        }

        $this->dispatchAccountCharacterNotification(
            recipient: $recipient,
            type: 'characters.primary_changed',
            titleKey: 'notifications.characters.primary_changed.title',
            bodyKey: 'notifications.characters.primary_changed.body',
            messageParams: [
                'character' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
            ],
            actionUrl: route('account.characters'),
            actor: $actor,
            subject: $character,
            payload: [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
            ],
            sendOffSite: true,
        );
    }

    public function notifyCharacterUnclaimed(Character $character, User $recipient, mixed $actor = null): void
    {
        $this->dispatchAccountCharacterNotification(
            recipient: $recipient,
            type: 'characters.unclaimed',
            titleKey: 'notifications.characters.unclaimed.title',
            bodyKey: 'notifications.characters.unclaimed.body',
            messageParams: [
                'character' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
            ],
            actionUrl: route('account.characters'),
            actor: $actor,
            subject: $character,
            payload: [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
            ],
            sendOffSite: true,
        );
    }

    private function characterRecipient(Character $character): ?User
    {
        $character->loadMissing('user');

        $recipient = $character->user;

        if (!$recipient instanceof User || !$recipient->account_character_notifications) {
            return null;
        }

        return $recipient;
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'discord' => 'Discord',
            'google' => 'Google',
            'xivauth' => 'XIVAuth',
            default => ucfirst($provider),
        };
    }

    private function characterMethodLabel(string $method): string
    {
        return match ($method) {
            'xivauth' => 'XIVAuth',
            'lodestone_token', 'manual' => 'Lodestone',
            default => ucfirst($method),
        };
    }

    /**
     * @param  array<string, mixed>  $messageParams
     * @param  array<string, mixed>  $payload
     */
    private function dispatchAccountCharacterNotification(
        User $recipient,
        string $type,
        string $titleKey,
        string $bodyKey,
        array $messageParams,
        ?string $actionUrl,
        mixed $actor,
        mixed $subject,
        array $payload,
        bool $sendOffSite,
    ): void {
        if (!$recipient->account_character_notifications) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: $type,
            category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            messageParams: $messageParams,
            actionUrl: $actionUrl,
            actor: $actor instanceof User ? $actor : $recipient,
            subject: $subject,
            payload: $payload,
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);

        if (!$sendOffSite) {
            return;
        }

        $this->notificationService->sendOffSiteNotifications($event, $recipient, [
            NotificationChannel::EMAIL,
            NotificationChannel::DISCORD,
        ]);
    }
}
