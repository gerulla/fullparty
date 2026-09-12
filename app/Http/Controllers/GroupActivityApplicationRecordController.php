<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Group;
use App\Services\Groups\ApplicantQueue\ApplicantRecordService;
use Illuminate\Http\JsonResponse;

class GroupActivityApplicationRecordController extends Controller
{
    public function show(Group $group, Activity $activity, ActivityApplication $application, ApplicantRecordService $records): JsonResponse
    {
        $this->authorize('manageDashboard', [$activity, $group]);
        abort_unless((int) $application->activity_id === (int) $activity->id, 404);

        return response()->json($records->forApplication($activity, $application));
    }
}
