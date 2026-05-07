<?php

namespace App\Services\Notifications;

use App\Models\SystemNotificationBroadcast;
use App\Models\SystemNotificationBroadcastRead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

class NotificationInboxService
{
    public function paginate(User $user, int $page = 1, int $perPage = 50): array
    {
        $baseQuery = $this->combinedQuery($user);
        $total = (clone $baseQuery)->count();

        $items = (clone $baseQuery)
            ->forPage($page, $perPage)
            ->get();

        return $this->serializePaginator(new Paginator(
            items: $this->serializeRows($items),
            total: $total,
            perPage: $perPage,
            currentPage: $page,
        ));
    }

    public function latest(User $user, int $limit = 5): array
    {
        return $this->serializeRows(
            $this->combinedQuery($user)
                ->limit($limit)
                ->get()
        )->all();
    }

    public function unreadCount(User $user): int
    {
        $userNotificationCount = $user->inAppNotifications()
            ->whereNull('read_at')
            ->count();

        $broadcastUnreadCount = DB::table('system_notification_broadcasts as broadcasts')
            ->join('notification_events as events', 'events.id', '=', 'broadcasts.notification_event_id')
            ->leftJoin('system_notification_broadcast_reads as reads', function ($join) use ($user) {
                $join->on('reads.system_notification_broadcast_id', '=', 'broadcasts.id')
                    ->where('reads.user_id', '=', $user->id);
            })
            ->when(!$user->system_notice_notifications, fn ($query) => $query->where('events.is_mandatory', true))
            ->whereNull('reads.read_at')
            ->count();

        return $userNotificationCount + $broadcastUnreadCount;
    }

    public function markAllRead(User $user): int
    {
        $updated = $user->inAppNotifications()
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
            ]);

        $unreadBroadcastIds = $this->visibleUnreadBroadcastIds($user);

        if ($unreadBroadcastIds !== []) {
            $timestamp = now();

            $rows = collect($unreadBroadcastIds)
                ->map(fn (int $broadcastId) => [
                    'system_notification_broadcast_id' => $broadcastId,
                    'user_id' => $user->id,
                    'read_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->all();

            SystemNotificationBroadcastRead::query()->insertOrIgnore($rows);
            $updated += count($unreadBroadcastIds);
        }

        return $updated;
    }

    public function markBroadcastAsRead(User $user, SystemNotificationBroadcast $broadcast): void
    {
        if (!$this->broadcastIsVisibleToUser($user, $broadcast)) {
            abort(404);
        }

        SystemNotificationBroadcastRead::query()->firstOrCreate(
            [
                'system_notification_broadcast_id' => $broadcast->id,
                'user_id' => $user->id,
            ],
            [
                'read_at' => now(),
            ],
        );
    }

    public function broadcastActionUrl(SystemNotificationBroadcast $broadcast): string
    {
        $broadcast->loadMissing('notificationEvent');

        return $broadcast->notificationEvent?->action_url
            ?: route('account.notifications.index');
    }

    public function serializeRows(iterable $rows): Collection
    {
        return collect($rows)
            ->map(function ($row) {
                $messageParams = $this->decodeJsonColumn($row->message_params ?? null);
                $payload = $this->decodeJsonColumn($row->payload ?? null);
                $sourceType = (string) $row->source_type;
                $sourceId = (int) $row->source_id;

                return [
                    'id' => sprintf('%s:%d', $sourceType, $sourceId),
                    'type' => $row->type,
                    'category' => $row->category,
                    'is_mandatory' => (bool) $row->is_mandatory,
                    'aggregate_count' => (int) ($row->aggregate_count ?? 1),
                    'aggregate_key' => $row->aggregate_key,
                    'title_key' => $row->title_key,
                    'body_key' => $row->body_key,
                    'message_params' => $messageParams,
                    'payload' => $payload,
                    'action_url' => $row->action_url,
                    'open_url' => $sourceType === 'broadcast'
                        ? route('account.notifications.broadcasts.open', $sourceId, false)
                        : route('account.notifications.open', $sourceId, false),
                    'created_at' => $row->created_at,
                    'read_at' => $row->read_at,
                    'is_unread' => $row->read_at === null,
                ];
            })
            ->values();
    }

    /**
     * @param  LengthAwarePaginator<array<string, mixed>>  $paginator
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     pagination: array{current_page: int, next_page: ?int, has_more_pages: bool, per_page: int, total: int}
     * }
     */
    public function serializePaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'items' => collect($paginator->items())->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
                'has_more_pages' => $paginator->hasMorePages(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function combinedQuery(User $user)
    {
        $userNotifications = DB::table('user_notifications')
            ->join('notification_events as events', 'events.id', '=', 'user_notifications.notification_event_id')
            ->where('user_notifications.user_id', $user->id)
            ->selectRaw('? as source_type', ['user'])
            ->selectRaw('user_notifications.id as source_id')
            ->selectRaw('events.type')
            ->selectRaw('events.category')
            ->selectRaw('events.is_mandatory')
            ->selectRaw('user_notifications.aggregate_count')
            ->selectRaw('user_notifications.aggregate_key')
            ->selectRaw('events.title_key')
            ->selectRaw('events.body_key')
            ->selectRaw('events.message_params')
            ->selectRaw('events.payload')
            ->selectRaw('events.action_url')
            ->selectRaw('user_notifications.created_at')
            ->selectRaw('user_notifications.read_at');

        $broadcasts = DB::table('system_notification_broadcasts as broadcasts')
            ->join('notification_events as events', 'events.id', '=', 'broadcasts.notification_event_id')
            ->leftJoin('system_notification_broadcast_reads as reads', function ($join) use ($user) {
                $join->on('reads.system_notification_broadcast_id', '=', 'broadcasts.id')
                    ->where('reads.user_id', '=', $user->id);
            })
            ->when(!$user->system_notice_notifications, fn ($query) => $query->where('events.is_mandatory', true))
            ->selectRaw('? as source_type', ['broadcast'])
            ->selectRaw('broadcasts.id as source_id')
            ->selectRaw('events.type')
            ->selectRaw('events.category')
            ->selectRaw('events.is_mandatory')
            ->selectRaw('1 as aggregate_count')
            ->selectRaw('NULL as aggregate_key')
            ->selectRaw('events.title_key')
            ->selectRaw('events.body_key')
            ->selectRaw('events.message_params')
            ->selectRaw('events.payload')
            ->selectRaw('events.action_url')
            ->selectRaw('broadcasts.created_at')
            ->selectRaw('reads.read_at');

        return DB::query()
            ->fromSub($userNotifications->unionAll($broadcasts), 'notifications')
            ->orderByDesc('created_at');
    }

    /**
     * @return array<int>
     */
    private function visibleUnreadBroadcastIds(User $user): array
    {
        return DB::table('system_notification_broadcasts as broadcasts')
            ->join('notification_events as events', 'events.id', '=', 'broadcasts.notification_event_id')
            ->leftJoin('system_notification_broadcast_reads as reads', function ($join) use ($user) {
                $join->on('reads.system_notification_broadcast_id', '=', 'broadcasts.id')
                    ->where('reads.user_id', '=', $user->id);
            })
            ->when(!$user->system_notice_notifications, fn ($query) => $query->where('events.is_mandatory', true))
            ->whereNull('reads.read_at')
            ->pluck('broadcasts.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function broadcastIsVisibleToUser(User $user, SystemNotificationBroadcast $broadcast): bool
    {
        $broadcast->loadMissing('notificationEvent');

        return (bool) $broadcast->notificationEvent?->is_mandatory || $user->system_notice_notifications;
    }

    private function decodeJsonColumn(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
