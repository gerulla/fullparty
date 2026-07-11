<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithGroupActivityAttendees;
use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ActivityCalendarExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class GroupActivityCalendarController extends Controller
{
    use InteractsWithGroupActivityAttendees;

    public function __invoke(
        Request $request,
        Group $group,
        Activity $activity,
        ActivityCalendarExportService $calendarExport,
        ?string $secretKey = null,
    ): Response {
        $this->ensureActivityBelongsToGroup($group, $activity);
        $group->loadMissing(['memberships', 'features']);

        if (! $this->canAccessOverview($request, $group, $activity, $secretKey)) {
            abort(404);
        }

        abort_unless($group->featureEnabled('calendar_sync_enabled'), 404);

        if (! $activity->starts_at || $activity->isArchived()) {
            abort(404);
        }

        $endsAt = $activity->starts_at->addMinutes(
            max(1, (int) round(((float) ($activity->duration_hours ?: 1)) * 60)),
        );

        if ($endsAt->isPast()) {
            abort(404);
        }

        $routeParameters = [
            'group' => $group,
            'activity' => $activity,
            'secretKey' => $secretKey,
        ];
        $contents = $calendarExport->build(
            $group,
            $activity,
            route('groups.activities.overview', array_filter($routeParameters)),
        );

        return response($contents, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="fullparty-run-'.$activity->id.'.ics"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
