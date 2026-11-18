<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;

class IntegrationRunController extends Controller
{
    public function show(Activity $activity): JsonResponse
    {
        $activity->loadMissing([
            'group:id,name,slug',
            'activityTypeVersion:id,name,small_image_url,banner_image_url,difficulty,prog_points',
            'organizer:id,name',
            'organizerCharacter:id,name,world,datacenter',
        ]);

        return response()->json([
            'data' => [
                'id' => $activity->id,
                'status' => $activity->status,
                'title' => $activity->title,
                'starts_at' => $activity->starts_at?->toIso8601String(),
                'duration_hours' => $activity->duration_hours,
                'datacenter' => $activity->datacenter,
                'run_style' => $activity->run_style,
                'intensity' => $activity->intensity,
                'is_public' => $activity->is_public,
                'needs_application' => $activity->needs_application,
                'allow_guest_applications' => $activity->allow_guest_applications,
                'group' => [
                    'id' => $activity->group?->id,
                    'name' => $activity->group?->name,
                    'slug' => $activity->group?->slug,
                ],
                'activity_type' => [
                    'name' => $activity->activityTypeVersion?->name,
                    'difficulty' => $activity->activityTypeVersion?->difficulty,
                    'small_image_url' => $activity->activityTypeVersion?->small_image_url,
                    'banner_image_url' => $activity->bannerImageUrl(),
                ],
                'organizer' => [
                    'id' => $activity->organizer?->id,
                    'name' => $activity->organizer?->name,
                    'character' => $activity->organizerCharacter ? [
                        'id' => $activity->organizerCharacter->id,
                        'name' => $activity->organizerCharacter->name,
                        'world' => $activity->organizerCharacter->world,
                        'datacenter' => $activity->organizerCharacter->datacenter,
                    ] : null,
                ],
            ],
        ]);
    }
}
