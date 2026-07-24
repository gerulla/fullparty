<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Http\Resources\Planner\RaidPlanResource;
use App\Models\RaidPlanAccessLink;
use App\Services\Planner\RaidPlanFightCatalog;
use App\Services\Planner\RaidPlanService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RaidPlanPageController extends Controller
{
    public function __construct(
        private readonly RaidPlanService $raidPlanService,
        private readonly RaidPlanFightCatalog $fightCatalog,
    ) {}

    public function view(Request $request, string $token): Response
    {
        $raidPlan = $this->raidPlanService->resolveByToken(
            $token,
            RaidPlanAccessLink::PERMISSION_VIEW,
        );

        return Inertia::render('Home', [
            'mode' => RaidPlanAccessLink::PERMISSION_VIEW,
            'raid_plan' => (new RaidPlanResource($raidPlan))->resolve($request),
            'fight_options' => $this->fightCatalog->options(),
        ]);
    }

    public function edit(Request $request, string $token): Response
    {
        $raidPlan = $this->raidPlanService->resolveByToken(
            $token,
            RaidPlanAccessLink::PERMISSION_EDIT,
        );

        return Inertia::render('Home', [
            'mode' => RaidPlanAccessLink::PERMISSION_EDIT,
            'raid_plan' => (new RaidPlanResource($raidPlan, true))->resolve($request),
            'fight_options' => $this->fightCatalog->options(),
        ]);
    }
}
