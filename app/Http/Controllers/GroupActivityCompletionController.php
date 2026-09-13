<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ActivityCompletionService;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Notifications\RunNotificationService;
use App\Support\Input\RequestTextInputSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivityCompletionController extends Controller
{
    public function __construct(
        private readonly ActivityCompletionService $completionService,
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly RunNotificationService $runNotificationService,
        private readonly RequestTextInputSanitizer $requestTextInputSanitizer,
    ) {}

    public function store(Request $request, Group $group, Activity $activity): JsonResponse
    {
        $this->authorize('manageDashboard', [$activity, $group]);

        if (! $activity->canBeCompleted()) {
            abort(403);
        }

        $this->requestTextInputSanitizer->sanitize($request, [], ['progress_notes']);

        $validated = $request->validate([
            'progress_entry_mode' => ['sometimes', 'nullable', 'string'],
            'progress_link_url' => [
                'sometimes',
                'nullable',
                'url:https',
                'max:2000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filled($value) && ! $this->isAllowedProgressLink((string) $value)) {
                        $fail(__('errors.the_progress_link_must_be_a_valid_ff_logs_report_url'));
                    }
                },
            ],
            'progress_notes' => ['sometimes', 'nullable', 'string', 'max:'.Activity::PROGRESS_NOTES_MAX_LENGTH],
            'furthest_progress_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'milestones' => ['sometimes', 'array'],
            'milestones.*' => ['array'],
            'milestones.*.milestone_key' => ['sometimes', 'string', 'max:255'],
            'milestones.*.kills' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'milestones.*.best_progress_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
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

    private function isAllowedProgressLink(string $value): bool
    {
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        $path = (string) parse_url($value, PHP_URL_PATH);

        return ($host === 'fflogs.com' || str_ends_with($host, '.fflogs.com'))
            && preg_match('~/(?:reports|report)/[A-Za-z0-9]+~', $path) === 1;
    }
}
