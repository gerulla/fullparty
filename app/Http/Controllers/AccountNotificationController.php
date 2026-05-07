<?php

namespace App\Http\Controllers;

use App\Models\SystemNotificationBroadcast;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationInboxService;
use App\Services\Notifications\NotificationRealtimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountNotificationController extends Controller
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly NotificationInboxService $notificationInboxService,
        private readonly NotificationRealtimeService $notificationRealtimeService,
    ) {}

    public function index(Request $request): Response
    {
        $pageData = $this->notificationInboxService->paginate(
            $request->user(),
            page: (int) $request->integer('page', 1),
            perPage: self::PAGE_SIZE,
        );

        return Inertia::render('Dashboard/Account/Notifications', [
            'notificationsPage' => $pageData,
            'unreadCount' => $this->notificationInboxService->unreadCount($request->user()),
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $pageData = $this->notificationInboxService->paginate(
            $request->user(),
            page: (int) $request->integer('page', 1),
            perPage: self::PAGE_SIZE,
        );

        return response()->json($pageData);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->notificationInboxService->unreadCount($request->user()),
            'latest' => $this->notificationInboxService->latest($request->user(), 5),
        ]);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $updated = $this->notificationInboxService->markAllRead($request->user());

        if ($updated > 0) {
            $this->notificationRealtimeService->broadcastUserInboxUpdated($request->user());
        }

        return back();
    }

    public function open(Request $request, UserNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        if ($notification->read_at === null) {
            $notification->update([
                'read_at' => now(),
            ]);

            $this->notificationRealtimeService->broadcastUserInboxUpdated($request->user());
        }

        $notification->loadMissing('notificationEvent');

        return redirect()->to(
            $notification->notificationEvent?->action_url
            ?: route('account.notifications.index')
        );
    }

    public function openBroadcast(Request $request, SystemNotificationBroadcast $broadcast): RedirectResponse
    {
        $this->notificationInboxService->markBroadcastAsRead($request->user(), $broadcast);
        $this->notificationRealtimeService->broadcastUserInboxUpdated($request->user());

        return redirect()->to(
            $this->notificationInboxService->broadcastActionUrl($broadcast)
        );
    }
}
