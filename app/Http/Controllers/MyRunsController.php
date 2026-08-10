<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ActivityIndexItemSerializer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyRunsController extends Controller
{
    public function __invoke(Request $request, ActivityIndexItemSerializer $serializer): Response
    {
        $user = $request->user();
        $groups = $user->runListGroups()
            ->with([
                'memberships',
                'activities.group',
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
            ->filter(fn (Group $group): bool => $group->hasMember($user->id) || $group->allowsPublicDashboardAccess())
            ->values();

        $activities = $groups
            ->flatMap(function (Group $group) use ($serializer, $user) {
                $canManageActivities = $group->hasModeratorAccess($user->id);

                return $group->activities
                    ->when(
                        ! $canManageActivities,
                        fn ($activities) => $activities->reject(
                            fn (Activity $activity): bool => Activity::isModeratorOnlyStatus($activity->status),
                        ),
                    )
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
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'profile_picture_url' => $group->profile_picture_url,
            ]),
        ]);
    }
}
