<?php

namespace App\Http\Controllers\Api\Members;

use App\Http\Controllers\Controller;
use App\Services\Integrations\MemberNotificationPresenter;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationInboxService $inbox, private readonly MemberNotificationPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $page = $this->inbox->paginate($request->user(), $request->integer('page', 1));
        $page['items'] = array_map(fn ($item) => $this->presenter->present($item, $request->user()), $page['items']);

        return response()->json($page);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->inbox->unreadCount($request->user()),
            'latest' => array_map(fn ($item) => $this->presenter->present($item, $request->user()), $this->inbox->latest($request->user()))]);
    }
}
