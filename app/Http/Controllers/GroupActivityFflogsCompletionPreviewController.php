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

        if (! $activity->canBeCompleted()) {
            abort(403);
        }

        $activity->loadMissing('activityTypeVersion');

        if (! $completionService->supportsFflogsCompletion($activity->activityTypeVersion)) {
            abort(422, __('errors.ff_logs_completion_is_not_supported_for_this_activity'));
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
                'message' => __('errors.unable_to_process_this_ff_logs_report_right_now'),
            ], 422);
        }
    }
}
