<?php

namespace App\Services\Notifications;

use App\Models\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class UserNotificationSerializer
{
    /**
     * @param  iterable<int, UserNotification>  $notifications
     * @return array<int, array<string, mixed>>
     */
    public function serializeCollection(iterable $notifications): array
    {
        return collect($notifications)
            ->map(fn (UserNotification $notification) => $this->serialize($notification))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(UserNotification $notification): array
    {
        $notification->loadMissing('notificationEvent');

        return [
            'id' => $notification->id,
            'type' => $notification->notificationEvent?->type,
            'category' => $notification->notificationEvent?->category,
            'is_mandatory' => (bool) $notification->notificationEvent?->is_mandatory,
            'title_key' => $notification->notificationEvent?->title_key,
            'body_key' => $notification->notificationEvent?->body_key,
            'message_params' => $notification->notificationEvent?->message_params,
            'payload' => $notification->notificationEvent?->payload,
            'action_url' => $notification->notificationEvent?->action_url,
            'open_url' => route('account.notifications.open', $notification, false),
            'created_at' => $notification->created_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'is_unread' => $notification->read_at === null,
        ];
    }

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     pagination: array{current_page: int, next_page: ?int, has_more_pages: bool, per_page: int, total: int}
     * }
     */
    public function serializePaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'items' => $this->serializeCollection($paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
                'has_more_pages' => $paginator->hasMorePages(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
