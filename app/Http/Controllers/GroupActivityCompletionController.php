<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ActivityCompletionService;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Notifications\RunNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivityCompletionController extends Controller
{
    public function __construct(
        private readonly ActivityCompletionService $completionService,
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly RunNotificationService $runNotificationService,
    ) {}

    public function store(Request $request, Group $group, Activity $activity): JsonResponse
    {
        $this->authorize('manageDashboard', [$activity, $group]);

        if (!$activity->canBeCompleted()) {
            abort(403);
        }

        $validated = $request->validate([
            'progress_entry_mode' => ['sometimes', 'nullable', 'string'],
            'progress_link_url' => ['sometimes', 'nullable', 'url', 'max:2000'],
            'progress_notes' => ['sometimes', 'nullable', 'string'],
            'furthest_progress_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'milestones' => ['sometimes', 'array'],
        ]);

        $changes = $this->completionService->complete($activity, $validated, (int) auth()->id());
        $this->activityAuditService->logActivityUpdated($activity->fresh(['group']), auth()->user(), $changes);
        $this->runNotificationService->notifyCompleted(
            $activity->fresh(['group', 'applications.user', 'applications.selectedCharacter']),
            auth()->user(),
        );

        return response()->json([
            'status' => 'completed',
        ]);
    }
}
