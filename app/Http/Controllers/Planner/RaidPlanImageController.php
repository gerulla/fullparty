<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planner\StoreRaidPlanImageRequest;
use App\Models\RaidPlanAccessLink;
use App\Services\ManagedImageStorage;
use App\Services\Planner\RaidPlanService;
use Illuminate\Http\JsonResponse;

class RaidPlanImageController extends Controller
{
    public function __construct(
        private readonly RaidPlanService $raidPlanService,
        private readonly ManagedImageStorage $imageStorage,
    ) {}

    public function __invoke(
        StoreRaidPlanImageRequest $request,
        string $token,
    ): JsonResponse {
        $raidPlan = $this->raidPlanService->resolveByToken(
            $token,
            RaidPlanAccessLink::PERMISSION_EDIT,
        );
        $url = $this->imageStorage->uploadImageIfPresent(
            $request->file('image'),
            "planner/raid-plans/{$raidPlan->id}",
        );

        return response()->json([
            'data' => [
                'url' => $url,
            ],
        ], 201);
    }
}
