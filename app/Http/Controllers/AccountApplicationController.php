<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Notifications\ApplicationNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountApplicationController extends Controller
{
    public function __construct(
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly ApplicationNotificationService $applicationNotificationService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $applications = ActivityApplication::query()
            ->with([
                'activity.group',
                'activity.activityTypeVersion',
                'selectedCharacter',
            ])
            ->where('user_id', $user->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ActivityApplication $application) => $this->serializeApplication($application));

        $activeApplications = $applications
            ->filter(fn (array $application) => $this->applicationIsActive($application))
            ->values()
            ->all();

        $historicalApplications = $applications
            ->reject(fn (array $application) => $this->applicationIsActive($application))
            ->values()
            ->all();

        return Inertia::render('Dashboard/Account/MyApplications', [
            'activeApplications' => $activeApplications,
            'historicalApplications' => $historicalApplications,
        ]);
    }

    public function destroy(Request $request, ActivityApplication $application): RedirectResponse
    {
        $user = $request->user();
        $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);

        if ((int) $application->user_id !== (int) $user->id) {
            abort(404);
        }

        if (!$this->applicationCanBeModified($application)) {
            throw ValidationException::withMessages([
                'application' => 'Only pending applications can be withdrawn.',
            ]);
        }

        DB::transaction(function () use ($application, $user): void {
            $application->update([
                'status' => ActivityApplication::STATUS_WITHDRAWN,
                'reviewed_by_user_id' => null,
                'reviewed_at' => now(),
            ]);

            $this->activityAuditService->logApplicationWithdrawn($application, $user);
        });

        $this->applicationNotificationService->notifyWithdrawn(
            $application->fresh(['activity.group', 'selectedCharacter', 'user']),
            $user,
        );

        return redirect()->route('account.applications');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApplication(ActivityApplication $application): array
    {
        $activity = $application->activity;
        $character = $application->selectedCharacter;
        $canModify = $this->applicationCanBeModified($application);

        return [
            'id' => $application->id,
            'status' => $application->status,
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'review_reason' => $application->review_reason,
            'notes' => $application->notes,
            'can_edit' => $canModify,
            'can_cancel' => $canModify,
            'group' => [
                'name' => $activity?->group?->name,
                'slug' => $activity?->group?->slug,
            ],
            'activity' => [
                'id' => $activity?->id,
                'title' => $activity?->title,
                'description' => $activity?->description,
                'status' => $activity?->status,
                'starts_at' => $activity?->starts_at?->toIso8601String(),
                'duration_hours' => $activity?->duration_hours,
                'is_public' => (bool) ($activity?->is_public ?? false),
                'secret_key' => $activity?->secret_key,
                'type_name' => $activity?->activityTypeVersion?->name,
            ],
            'character' => [
                'name' => $character?->name ?? $application->applicant_character_name,
                'world' => $character?->world ?? $application->applicant_world,
                'datacenter' => $character?->datacenter ?? $application->applicant_datacenter,
                'avatar_url' => $character?->avatar_url ?? $application->applicant_avatar_url,
            ],
        ];
    }

    private function applicationCanBeModified(ActivityApplication $application): bool
    {
        $activity = $application->activity;

        if (!$activity) {
            return false;
        }

        return $application->status === ActivityApplication::STATUS_PENDING
            && $activity->needs_application
            && !Activity::isArchivedStatus($activity->status);
    }

    /**
     * @param  array<string, mixed>  $application
     */
    private function applicationIsActive(array $application): bool
    {
        $activityStatus = (string) ($application['activity']['status'] ?? '');
        $applicationStatus = (string) ($application['status'] ?? '');

        if (Activity::isArchivedStatus($activityStatus)) {
            return false;
        }

        return in_array($applicationStatus, [
            ActivityApplication::STATUS_PENDING,
            ActivityApplication::STATUS_APPROVED,
            ActivityApplication::STATUS_ON_BENCH,
        ], true);
    }
}
