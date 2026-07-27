<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planner\RaidPlanRequest;
use App\Http\Resources\Planner\RaidPlanResource;
use App\Models\RaidPlanAccessLink;
use App\Services\Planner\RaidPlanService;
use Illuminate\Http\RedirectResponse;

class RaidPlanController extends Controller
{
    public function __construct(
        private readonly RaidPlanService $raidPlanService,
    ) {}

    public function store(RaidPlanRequest $request): RedirectResponse
    {
        $raidPlan = $this->raidPlanService->create(
            $request->user(),
            $request->validated(),
        );

        return redirect()->route('planner.edit', [
            'token' => $raidPlan->accessToken(
                RaidPlanAccessLink::PERMISSION_EDIT,
            ),
        ]);
    }

    public function update(
        RaidPlanRequest $request,
        string $token
    ): RedirectResponse|RaidPlanResource {
        $raidPlan = $this->raidPlanService->resolveByToken(
            $token,
            RaidPlanAccessLink::PERMISSION_EDIT,
        );

        $raidPlan = $this->raidPlanService->update(
            $raidPlan,
            $request->validated(),
        );

        if ($request->expectsJson()) {
            return new RaidPlanResource($raidPlan, true);
        }

        return redirect()->route('planner.edit', ['token' => $token]);
    }
}
