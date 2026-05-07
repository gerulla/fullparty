<?php

namespace App\Services\Notifications;

use App\Mail\NotificationDeliveryMail;
use App\Models\NotificationDelivery;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailNotificationDeliveryService
{
    public function __construct(
        private readonly NotificationMessageRenderer $messageRenderer,
    ) {}

    public function send(int $deliveryId): void
    {
        $delivery = NotificationDelivery::query()
            ->with(['notificationEvent', 'user'])
            ->find($deliveryId);

        if (!$delivery || $delivery->channel !== NotificationChannel::EMAIL) {
            return;
        }

        if ($delivery->status !== NotificationDelivery::STATUS_PENDING) {
            return;
        }

        $recipient = $delivery->user;
        $event = $delivery->notificationEvent;

        if (!$recipient || !$event || !filled($recipient->email)) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_SKIPPED,
                'status_reason' => 'missing_email_address',
                'skipped_at' => now(),
            ]);

            return;
        }

        try {
            $message = $this->messageRenderer->render($event, $recipient);
            $mailerName = $this->resolveMailerName($event);

            $mail = $mailerName
                ? Mail::mailer($mailerName)
                : Mail::mailer();

            $mail->to($recipient->email)->send(
                new NotificationDeliveryMail(
                    subjectLine: $message['subject'],
                    bodyText: $message['body'],
                    actionUrl: $message['action_url'],
                )
            );

            $delivery->update([
                'status' => NotificationDelivery::STATUS_SENT,
                'status_reason' => null,
                'sent_at' => now(),
                'failed_at' => null,
                'skipped_at' => null,
            ]);
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_FAILED,
                'status_reason' => 'email_send_failed',
                'failed_at' => now(),
                'response_payload' => [
                    'message' => $exception->getMessage(),
                ],
            ]);
        }
    }

    private function resolveMailerName(\App\Models\NotificationEvent $event): ?string
    {
        $defaultMailer = (string) config('mail.default');

        if ($defaultMailer !== 'postmark') {
            return null;
        }

        if (
            $event->category === \App\Support\Notifications\NotificationCategory::SYSTEM_NOTICES
            && !$event->is_mandatory
        ) {
            return 'postmark_broadcast';
        }

        return 'postmark';
    }
}
