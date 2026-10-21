<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ActivityIndexItemSerializer;
use App\Services\Groups\MyRunsCommitmentService;
use App\Services\Runs\RunAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyRunsController extends Controller
{
    public function __invoke(Request $request, ActivityIndexItemSerializer $serializer, MyRunsCommitmentService $commitments, RunAccessService $access): Response
    {
        $user = $request->user();
        $groups = $user->runListGroups()
            ->whereDoesntHave('bans', fn ($query) => $query->where('user_id', $user->id))
            ->with([
                'memberships',
                'bans',
                'activities' => fn ($query) => $query->tap(fn ($query) => $access->scopeVisibleTo($query, $user->id)),
                'activities.group.memberships',
                'activities.group.bans',
                'activities.organizer',
                'activities.organizerCharacter',
                'activities.activityType',
                'activities.activityTypeVersion',
                'activities.slots',
                'activities.applications',
                'activities.progressMilestones',
            ])
            ->orderBy('groups.name')
            ->get()
            ->filter(fn (Group $group): bool => $group->hasMember($user->id) || $group->activities->isNotEmpty())
            ->values();

        $activities = $groups
            ->flatMap(function (Group $group) use ($serializer, $user) {
                $canManageActivities = $group->hasModeratorAccess($user->id);

                return $group->activities
                    ->sortByDesc('updated_at')
                    ->map(fn (Activity $activity): array => $serializer->serialize(
                        $activity,
                        $user->id,
                        $canManageActivities,
                    ));
            })
            ->values();

        return Inertia::render('Dashboard/Runs/MyRuns', [
            'activities' => $activities,
            'commitments' => $commitments->forUser($user),
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'profile_picture_url' => $group->profile_picture_url,
            ]),
        ]);
    }
}
