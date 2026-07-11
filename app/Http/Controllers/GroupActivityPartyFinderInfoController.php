<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublishActivityPartyFinderInfoRequest;
use App\Http\Resources\ActivityPartyFinderInfoResource;
use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ActivityPartyFinderInfoService;
use Illuminate\Http\JsonResponse;

class GroupActivityPartyFinderInfoController extends Controller
{
    public function store(
        PublishActivityPartyFinderInfoRequest $request,
        Group $group,
        Activity $activity,
        ActivityPartyFinderInfoService $partyFinderInfoService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        $info = $partyFinderInfoService->publish(
            $activity,
            $request->user(),
            $request->validated(),
        );

        return ActivityPartyFinderInfoResource::make($info)
            ->response()
            ->setStatusCode(200);
    }
}
