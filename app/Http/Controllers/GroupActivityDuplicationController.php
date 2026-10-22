<?php

namespace App\Http\Controllers;

use App\Http\Requests\DuplicateGroupActivityRequest;
use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ActivityDuplicationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class GroupActivityDuplicationController extends Controller
{
    public function store(
        DuplicateGroupActivityRequest $request,
        Group $group,
        Activity $activity,
        ActivityDuplicationService $activityDuplicationService,
    ): RedirectResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        $validated = $request->validated();
        $duplicate = $activityDuplicationService->duplicate(
            source: $activity,
            actor: $request->user(),
            title: (string) $validated['title'],
            startsAt: CarbonImmutable::createFromFormat(
                'Y-m-d\TH:i',
                (string) $validated['starts_at'],
                'UTC',
            )->utc(),
            status: (string) $validated['status'],
            copyBench: (bool) $validated['copy_bench'],
            copyFillIns: (bool) $validated['copy_fill_ins'],
            isPublic: isset($validated['is_public']) ? (bool) $validated['is_public'] : null,
            allowGuests: isset($validated['allow_guest_applications']) ? (bool) $validated['allow_guest_applications'] : null,
        );

        return redirect()
            ->route('groups.dashboard.activities.show', [
                'group' => $group,
                'activity' => $duplicate,
            ])
            ->with('success', 'activity_duplicated');
    }
}
