<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ApplicantQueue\ApplicantQueuePayloadBuilder;
use Illuminate\Http\JsonResponse;

class GroupActivityApplicantQueueController extends Controller
{
    public function show(Group $group, Activity $activity, ApplicantQueuePayloadBuilder $payloadBuilder): JsonResponse
    {
        $this->authorize('manageDashboard', [$activity, $group]);

        $activity->load([
            'activityTypeVersion',
            'applications' => fn ($query) => $query
                ->where('status', 'pending')
                ->with([
                    'user',
                    'selectedCharacter.occultProgress',
                    'selectedCharacter.phantomJobs',
                    'answers',
                ]),
        ]);

        return response()->json($payloadBuilder->build($activity));
    }
}
