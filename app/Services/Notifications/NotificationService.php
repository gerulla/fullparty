<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class NotificationService
{
    public function __construct(
        private readonly NotificationDeliveryDispatcher $deliveryDispatcher,
    ) {}

    public function createEvent(
        string $type,
        string $category,
        string $titleKey,
        ?string $bodyKey = null,
        array $messageParams = [],
        ?string $actionUrl = null,
        User|int|null $actor = null,
        Model|array|null $subject = null,
        ?array $payload = null,
        bool $isMandatory = false,
    ): NotificationEvent {
        NotificationCategory::ensureValid($category);

        $subjectPayload = $this->resolveSubject($subject);

        return NotificationEvent::query()->create([
            'type' => $type,
            'category' => $category,
            'is_mandatory' => $isMandatory,
            'actor_user_id' => $this->resolveActorId($actor),
            'subject_type' => $subjectPayload['subject_type'],
            'subject_id' => $subjectPayload['subject_id'],
            'title_key' => $titleKey,
            'body_key' => $bodyKey,
            'message_params' => $messageParams === [] ? null : $messageParams,
            'action_url' => filled($actionUrl) ? $actionUrl : null,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }

    /**
     * @param  User|iterable<int, User>  $recipients
     * @return Collection<int, UserNotification>
     */
    public function sendInAppNotifications(NotificationEvent $event, User|iterable $recipients): Collection
    {
        $normalizedRecipients = $this->normalizeRecipients($recipients);

        return $normalizedRecipients
            ->filter(fn (User $recipient) => $this->recipientWantsEvent($recipient, $event))
            ->map(fn (User $recipient) => UserNotification::query()->firstOrCreate([
                'notification_event_id' => $event->id,
                'user_id' => $recipient->id,
            ]))
            ->values();
    }

    /**
     * @param  User|iterable<int, User>  $recipients
     * @param  array<int, string>|null  $channels
     * @return Collection<int, NotificationDelivery>
     */
    public function sendOffSiteNotifications(
        NotificationEvent $event,
        User|iterable $recipients,
        ?array $channels = null,
    ): Collection {
        $normalizedRecipients = $this->normalizeRecipients($recipients);
        $normalizedRecipients->loadMissing('socialAccounts');

        $resolvedChannels = $this->normalizeChannels($channels);
        $deliveries = collect();
        $timestamp = now();

        foreach ($normalizedRecipients as $recipient) {
            foreach ($resolvedChannels as $channel) {
                $outcome = $this->resolveDeliveryOutcome($event, $recipient, $channel);

                $delivery = NotificationDelivery::query()->firstOrNew([
                    'notification_event_id' => $event->id,
                    'user_id' => $recipient->id,
                    'channel' => $channel,
                ]);

                if (
                    $delivery->exists
                    && in_array($delivery->status, [
                        NotificationDelivery::STATUS_PENDING,
                        NotificationDelivery::STATUS_SENT,
                    ], true)
                ) {
                    $deliveries->push($delivery);
                    continue;
                }

                $delivery->fill([
                    'status' => $outcome['status'],
                    'target' => $outcome['target'],
                    'queued_at' => $outcome['status'] === NotificationDelivery::STATUS_PENDING ? $timestamp : null,
                    'sent_at' => null,
                    'failed_at' => null,
                    'skipped_at' => $outcome['status'] === NotificationDelivery::STATUS_SKIPPED ? $timestamp : null,
                    'status_reason' => $outcome['reason'],
                    'response_payload' => null,
                ]);

                $delivery->save();

                if ($delivery->status === NotificationDelivery::STATUS_PENDING) {
                    $this->deliveryDispatcher->dispatch($delivery);
                }

                $deliveries->push($delivery->fresh());
            }
        }

        return $deliveries;
    }

    /**
     * @param  array<int, string>|null  $channels
     * @return array<int, string>
     */
    private function normalizeChannels(?array $channels): array
    {
        $resolvedChannels = $channels ?? NotificationChannel::VALUES;

        foreach ($resolvedChannels as $channel) {
            NotificationChannel::ensureValid($channel);
        }

        return array_values(array_unique($resolvedChannels));
    }

    /**
     * @param  User|iterable<int, User>  $recipients
     * @return EloquentCollection<int, User>
     */
    private function normalizeRecipients(User|iterable $recipients): EloquentCollection
    {
        $items = $recipients instanceof User
            ? [$recipients]
            : collect($recipients)->all();

        foreach ($items as $recipient) {
            if (!$recipient instanceof User) {
                throw new InvalidArgumentException('Notification recipients must be persisted user models.');
            }

            if (!$recipient->exists) {
                throw new InvalidArgumentException('Notification recipients must be persisted user models.');
            }
        }

        return new EloquentCollection(
            collect($items)
                ->unique(fn (User $recipient) => $recipient->id)
                ->values()
                ->all()
        );
    }

    private function recipientWantsEvent(User $recipient, NotificationEvent $event): bool
    {
        if ($event->is_mandatory) {
            return true;
        }

        $preferenceField = NotificationCategory::preferenceField($event->category);

        return (bool) $recipient->{$preferenceField};
    }

    /**
     * @return array{status: string, reason: ?string, target: ?string}
     */
    private function resolveDeliveryOutcome(NotificationEvent $event, User $recipient, string $channel): array
    {
        if (!$this->recipientWantsEvent($recipient, $event)) {
            return [
                'status' => NotificationDelivery::STATUS_SKIPPED,
                'reason' => 'category_preference_disabled',
                'target' => $this->resolveChannelTarget($recipient, $channel),
            ];
        }

        return match ($channel) {
            NotificationChannel::EMAIL => $this->resolveEmailDeliveryOutcome($recipient),
            NotificationChannel::DISCORD => $this->resolveDiscordDeliveryOutcome($recipient),
            default => throw new InvalidArgumentException("Invalid notification channel [{$channel}] supplied."),
        };
    }

    /**
     * @return array{status: string, reason: ?string, target: ?string}
     */
    private function resolveEmailDeliveryOutcome(User $recipient): array
    {
        if (!$recipient->email_notifications) {
            return [
                'status' => NotificationDelivery::STATUS_SKIPPED,
                'reason' => 'channel_preference_disabled',
                'target' => filled($recipient->email) ? $recipient->email : null,
            ];
        }

        if (!filled($recipient->email)) {
            return [
                'status' => NotificationDelivery::STATUS_SKIPPED,
                'reason' => 'missing_email_address',
                'target' => null,
            ];
        }

        return [
            'status' => NotificationDelivery::STATUS_PENDING,
            'reason' => null,
            'target' => $recipient->email,
        ];
    }

    /**
     * @return array{status: string, reason: ?string, target: ?string}
     */
    private function resolveDiscordDeliveryOutcome(User $recipient): array
    {
        $target = $this->resolveDiscordTarget($recipient);

        if (!$recipient->discord_notifications) {
            return [
                'status' => NotificationDelivery::STATUS_SKIPPED,
                'reason' => 'channel_preference_disabled',
                'target' => $target,
            ];
        }

        if (!$target) {
            return [
                'status' => NotificationDelivery::STATUS_SKIPPED,
                'reason' => 'missing_discord_account',
                'target' => null,
            ];
        }

        return [
            'status' => NotificationDelivery::STATUS_PENDING,
            'reason' => null,
            'target' => $target,
        ];
    }

    private function resolveChannelTarget(User $recipient, string $channel): ?string
    {
        return match ($channel) {
            NotificationChannel::EMAIL => filled($recipient->email) ? $recipient->email : null,
            NotificationChannel::DISCORD => $this->resolveDiscordTarget($recipient),
            default => null,
        };
    }

    private function resolveDiscordTarget(User $recipient): ?string
    {
        $discordAccount = $recipient->socialAccounts
            ->first(fn (SocialAccount $socialAccount) => $socialAccount->provider === NotificationChannel::DISCORD);

        return filled($discordAccount?->provider_user_id) ? $discordAccount->provider_user_id : null;
    }

    private function resolveActorId(User|int|null $actor): ?int
    {
        if ($actor instanceof User) {
            return $actor->id;
        }

        return $actor;
    }

    /**
     * @return array{subject_type: ?string, subject_id: ?int}
     */
    private function resolveSubject(Model|array|null $subject): array
    {
        if ($subject instanceof Model) {
            return [
                'subject_type' => $subject::class,
                'subject_id' => (int) $subject->getKey(),
            ];
        }

        if ($subject === null) {
            return [
                'subject_type' => null,
                'subject_id' => null,
            ];
        }

        if (
            array_key_exists('subject_type', $subject)
            && array_key_exists('subject_id', $subject)
        ) {
            return [
                'subject_type' => $subject['subject_type'],
                'subject_id' => $subject['subject_id'],
            ];
        }

        throw new InvalidArgumentException('Notification subject must be a model, null, or an array with subject_type and subject_id.');
    }
}
