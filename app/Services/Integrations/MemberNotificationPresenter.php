<?php

namespace App\Services\Integrations;

use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\NotificationMessageRenderer;

final class MemberNotificationPresenter
{
    public function __construct(private readonly NotificationMessageRenderer $renderer) {}

    public function present(array $item, User $user): array
    {
        $event = new NotificationEvent;
        $event->forceFill(array_intersect_key($item, array_flip(['type', 'category', 'title_key', 'body_key', 'message_params', 'payload', 'action_url'])));
        $message = $this->renderer->render($event, $user, app()->getLocale());
        [$source, $id] = explode(':', $item['id'], 2);

        return [
            'id' => $item['id'], 'source' => $source, 'source_id' => (int) $id,
            'type' => $item['type'], 'category' => $item['category'],
            'title' => $message['subject'], 'body' => $message['body'],
            'action_url' => $message['action_url'], 'created_at' => $item['created_at'],
            'read_at' => $item['read_at'], 'is_unread' => $item['is_unread'],
            'is_mandatory' => $item['is_mandatory'], 'aggregate_count' => $item['aggregate_count'],
        ];
    }
}
