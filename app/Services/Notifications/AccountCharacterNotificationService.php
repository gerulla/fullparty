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
        if (!$user->account_character_notifications) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: 'user.social_account.linked',
            category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
            titleKey: 'notifications.user.social_account.linked.title',
            bodyKey: 'notifications.user.social_account.linked.body',
            messageParams: [
                'provider' => $this->providerLabel($provider),
            ],
            actionUrl: route('settings'),
            actor: $actor instanceof User ? $actor : $user,
            subject: $user,
            payload: [
                'provider' => $provider,
            ],
        );

        $this->notificationService->sendInAppNotifications($event, $user);
        $this->notificationService->sendOffSiteNotifications($event, $user, [
            NotificationChannel::EMAIL,
            NotificationChannel::DISCORD,
        ]);
    }

    public function notifySocialAccountUnlinked(User $user, string $provider, mixed $actor = null): void
    {
        if (!$user->account_character_notifications) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: 'user.social_account.unlinked',
            category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
            titleKey: 'notifications.user.social_account.unlinked.title',
            bodyKey: 'notifications.user.social_account.unlinked.body',
            messageParams: [
                'provider' => $this->providerLabel($provider),
            ],
            actionUrl: route('settings'),
            actor: $actor instanceof User ? $actor : $user,
            subject: $user,
            payload: [
                'provider' => $provider,
            ],
        );

        $this->notificationService->sendInAppNotifications($event, $user);
        $this->notificationService->sendOffSiteNotifications($event, $user, [
            NotificationChannel::EMAIL,
            NotificationChannel::DISCORD,
        ]);
    }

    public function notifyCharacterAdded(Character $character, string $method, mixed $actor = null): void
    {
        $recipient = $this->characterRecipient($character);

        if (!$recipient) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: 'characters.added',
            category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
            titleKey: 'notifications.characters.added.title',
            bodyKey: 'notifications.characters.added.body',
            messageParams: [
                'character' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
                'method' => $this->characterMethodLabel($method),
            ],
            actionUrl: route('account.characters'),
            actor: $actor instanceof User ? $actor : $recipient,
            subject: $character,
            payload: [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
                'method' => $method,
            ],
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);
        $this->notificationService->sendOffSiteNotifications($event, $recipient, [
            NotificationChannel::EMAIL,
            NotificationChannel::DISCORD,
        ]);
    }

    public function notifyCharacterRefreshed(Character $character, mixed $actor = null): void
    {
        $recipient = $this->characterRecipient($character);

        if (!$recipient) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: 'characters.refreshed',
            category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
            titleKey: 'notifications.characters.refreshed.title',
            bodyKey: 'notifications.characters.refreshed.body',
            messageParams: [
                'character' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
            ],
            actionUrl: route('account.characters'),
            actor: $actor instanceof User ? $actor : $recipient,
            subject: $character,
            payload: [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
            ],
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);
    }

    public function notifyPrimaryCharacterChanged(Character $character, mixed $actor = null): void
    {
        $recipient = $this->characterRecipient($character);

        if (!$recipient) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: 'characters.primary_changed',
            category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
            titleKey: 'notifications.characters.primary_changed.title',
            bodyKey: 'notifications.characters.primary_changed.body',
            messageParams: [
                'character' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
            ],
            actionUrl: route('account.characters'),
            actor: $actor instanceof User ? $actor : $recipient,
            subject: $character,
            payload: [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
            ],
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);
        $this->notificationService->sendOffSiteNotifications($event, $recipient, [
            NotificationChannel::EMAIL,
            NotificationChannel::DISCORD,
        ]);
    }

    public function notifyCharacterUnclaimed(Character $character, User $recipient, mixed $actor = null): void
    {
        if (!$recipient->account_character_notifications) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: 'characters.unclaimed',
            category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
            titleKey: 'notifications.characters.unclaimed.title',
            bodyKey: 'notifications.characters.unclaimed.body',
            messageParams: [
                'character' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
            ],
            actionUrl: route('account.characters'),
            actor: $actor instanceof User ? $actor : $recipient,
            subject: $character,
            payload: [
                'character_id' => $character->id,
                'lodestone_id' => $character->lodestone_id,
            ],
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);
        $this->notificationService->sendOffSiteNotifications($event, $recipient, [
            NotificationChannel::EMAIL,
            NotificationChannel::DISCORD,
        ]);
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
}
