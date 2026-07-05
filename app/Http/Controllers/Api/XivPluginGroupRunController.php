<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\XivPlugin\XivPluginRunListResource;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class XivPluginGroupRunController extends Controller
{
    public function index(Request $request, Group $group): JsonResponse
    {
        $user = $request->user();
        $group->loadMissing('memberships');

        abort_unless($group->hasMember($user->id), 404);

        $canModerate = $group->hasModeratorAccess($user->id);
        $visibleFrom = now()->subHours(6);

        $runs = $group->activities()
            ->with(['activityTypeVersion'])
            ->withCount([
                'applications as active_application_count' => fn ($query) => $query->whereIn('status', ActivityApplication::ACTIVE_STATUSES),
            ])
            ->where(function ($query) use ($canModerate, $visibleFrom) {
                $query->where(function ($visibleRuns) use ($visibleFrom) {
                    $visibleRuns
                        ->where('status', '!=', Activity::STATUS_DRAFT)
                        ->whereNotIn('status', Activity::ARCHIVED_STATUSES)
                        ->where('starts_at', '>=', $visibleFrom);
                });

                if ($canModerate) {
                    $query->orWhere(function ($draftRuns) use ($visibleFrom) {
                        $draftRuns
                            ->where('status', Activity::STATUS_DRAFT)
                            ->where(function ($datedDrafts) use ($visibleFrom) {
                                $datedDrafts
                                    ->whereNull('starts_at')
                                    ->orWhere('starts_at', '>=', $visibleFrom);
                            });
                    });
                }
            })
            ->orderByRaw('CASE WHEN starts_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return (new XivPluginRunListResource($runs, $canModerate, $group))->response();
    }
}
