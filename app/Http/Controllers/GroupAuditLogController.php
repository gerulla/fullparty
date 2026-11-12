<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditLogIndexRequest;
use App\Models\AuditLog;
use App\Models\Group;
use App\Services\Audit\AuditLogFeedService;
use App\Services\Audit\GroupAuditLogPresenter;
use Illuminate\Http\JsonResponse;

class GroupAuditLogController extends Controller
{
    public function index(Group $group, AuditLogIndexRequest $request, AuditLogFeedService $feed, GroupAuditLogPresenter $presenter): JsonResponse
    {
        $this->authorizeModeratorAccess($group);

        $query = AuditLog::query()
            ->where('scope_type', 'group')
            ->where('scope_id', $group->id);
        $auditLogs = $feed->paginate($query, $request->filters(), $request->validated('cursor'));
        $payload = [
            'nextCursor' => $auditLogs->nextCursor()?->encode(),
            'auditLogs' => $presenter->rows($auditLogs->getCollection(), $group),
        ];

        if ($request->boolean('include_options')) {
            $payload['filters'] = $feed->options($query, $group);
        }

        return response()->json($payload);
    }

    public function show(Group $group, AuditLog $auditLog, GroupAuditLogPresenter $presenter): JsonResponse
    {
        $this->authorizeModeratorAccess($group);
        abort_unless($auditLog->scope_type === 'group' && (int) $auditLog->scope_id === (int) $group->id, 404);

        return response()->json(['event' => $presenter->detail($auditLog, $group)]);
    }

    private function authorizeModeratorAccess(Group $group): void
    {
        if (! $group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }
}
