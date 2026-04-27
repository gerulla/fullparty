<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Group;
use App\Services\FFLogs\ActivityReportProgressFetcher;
use App\Services\Groups\ActivityCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GroupActivityFflogsCompletionPreviewController extends Controller
{
    public function show(
        Request $request,
        Group $group,
        Activity $activity,
        ActivityCompletionService $completionService,
        ActivityReportProgressFetcher $reportProgressFetcher,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if (!$activity->canBeCompleted()) {
            abort(403);
        }

        $activity->loadMissing('activityTypeVersion');

        if (!$completionService->supportsFflogsCompletion($activity->activityTypeVersion)) {
            abort(422, 'FF Logs completion is not supported for this activity.');
        }

        $validated = $request->validate([
            'progress_link_url' => ['required', 'string', 'max:2000'],
        ]);

        try {
            return response()->json([
                'preview' => $reportProgressFetcher->preview($activity, (string) $validated['progress_link_url']),
            ]);
        } catch (RuntimeException $exception) {
            Log::warning('FF Logs completion preview failed.', [
                'activity_id' => $activity->id,
                'group_id' => $group->id,
                'report_input' => (string) $validated['progress_link_url'],
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to process this FF Logs report right now.',
            ], 422);
        }
    }
}
