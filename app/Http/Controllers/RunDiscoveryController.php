<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunDiscoveryFilterRequest;
use App\Models\Activity;
use App\Services\Runs\RunBrowseService;
use App\Services\Runs\RunDiscoveryService;
use App\Support\Seo\ServerMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RunDiscoveryController extends Controller
{
    public function __construct(
        private readonly RunDiscoveryService $runDiscoveryService,
        private readonly RunBrowseService $runBrowseService,
        private readonly ServerMeta $serverMeta,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Dashboard/Runs/Index', [
            'lookups' => $this->runDiscoveryService->buildLookups(request()->user()),
            'browseSections' => Inertia::defer(fn () => $this->runBrowseService->sections(request()->user())),
            'trendingActivities' => Inertia::defer(fn () => $this->runBrowseService->trendingActivities(request()->user())),
        ])->withViewData('serverMeta', $this->serverMeta->runDiscovery());
    }

    public function searchOptions(Request $request): JsonResponse
    {
        return response()->json($this->runDiscoveryService->searchOptions($request->user()));
    }

    public function discover(RunDiscoveryFilterRequest $request): JsonResponse
    {
        return response()->json(
            $this->runDiscoveryService->discoverResultsForUser(
                $request->user(),
                $request->validated(),
            )
        );
    }

    public function count(RunDiscoveryFilterRequest $request): JsonResponse
    {
        return response()->json([
            'total' => $this->runDiscoveryService->countMatchesForUser($request->user(), $request->validated()),
        ]);
    }

    public function save(Activity $activity): JsonResponse
    {
        $user = request()->user();

        if (! $this->runDiscoveryService->canUserInteractWithDiscoveryActivity($activity, $user)) {
            abort(404);
        }

        $user->savedActivities()->syncWithoutDetaching([$activity->id]);

        return response()->json([
            'saved' => true,
        ]);
    }

    public function unsave(Activity $activity): JsonResponse
    {
        $user = request()->user();

        // Removing an owned bookmark must still work after access or availability changes.
        if (! $user->savedActivities()->whereKey($activity->id)->exists()
            && ! $this->runDiscoveryService->canUserInteractWithDiscoveryActivity($activity, $user)) {
            abort(404);
        }

        $user->savedActivities()->detach($activity->id);

        return response()->json([
            'saved' => false,
        ]);
    }
}
