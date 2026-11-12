<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\V2HomeDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class V2HomeController extends Controller
{
    public function __construct(private readonly V2HomeDataService $data) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('V2/Home', [
            'user' => Inertia::defer(fn () => $this->data->user($request->user()), 'v2-user'),
            'applications' => Inertia::defer(fn () => $this->data->applications($request->user()), 'v2-applications'),
            'applicationHistory' => Inertia::defer(fn () => $this->data->applicationHistory($request->user()), 'v2-application-history'),
            'groups' => Inertia::defer(fn () => $this->data->groups($request->user()), 'v2-groups'),
            'upcomingRuns' => Inertia::defer(fn () => $this->data->upcomingRuns($request->user()), 'v2-upcoming-runs'),
            'hostedRuns' => Inertia::defer(fn () => $this->data->hostedRuns($request->user()), 'v2-hosted-runs'),
            'groupRuns' => Inertia::defer(fn () => $this->data->groupRuns($request->user()), 'v2-group-runs'),
        ]);
    }
}
