<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Services\Planner\RaidPlanFightCatalog;
use Inertia\Inertia;
use Inertia\Response;

class PlannerController extends Controller
{
    public function __construct(
        private readonly RaidPlanFightCatalog $fightCatalog,
    ) {}

    public function __invoke(): Response
    {
        return Inertia::render('Home', [
            'mode' => 'edit',
            'raid_plan' => null,
            'store_url' => route('planner.raid-plans.store'),
            'fight_options' => $this->fightCatalog->options(),
        ]);
    }
}
