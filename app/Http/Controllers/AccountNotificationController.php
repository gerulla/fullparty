<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Services\Notifications\UserNotificationSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountNotificationController extends Controller
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly UserNotificationSerializer $notificationSerializer,
    ) {}

    public function index(Request $request): Response
    {
        $pageData = $this->notificationSerializer->serializePaginator(
            $this->notificationsQuery($request)->paginate(self::PAGE_SIZE)
        );

        return Inertia::render('Dashboard/Account/Notifications', [
            'notificationsPage' => $pageData,
            'unreadCount' => $request->user()
                ->inAppNotifications()
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $pageData = $this->notificationSerializer->serializePaginator(
            $this->notificationsQuery($request)->paginate(self::PAGE_SIZE)
        );

        return response()->json($pageData);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $request->user()
                ->inAppNotifications()
                ->whereNull('read_at')
                ->count(),
            'latest' => $this->notificationSerializer->serializeCollection(
                $request->user()
                    ->inAppNotifications()
                    ->with('notificationEvent')
                    ->latest('created_at')
                    ->limit(5)
                    ->get()
            ),
        ]);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()
            ->inAppNotifications()
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
            ]);

        return back();
    }

    public function open(Request $request, UserNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        if ($notification->read_at === null) {
            $notification->update([
                'read_at' => now(),
            ]);
        }

        $notification->loadMissing('notificationEvent');

        return redirect()->to(
            $notification->notificationEvent?->action_url
            ?: route('account.notifications.index')
        );
    }

    private function notificationsQuery(Request $request)
    {
        return $request->user()
            ->inAppNotifications()
            ->with('notificationEvent')
            ->orderByDesc('created_at');
    }
}
