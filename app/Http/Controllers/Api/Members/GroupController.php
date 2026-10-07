<?php

namespace App\Http\Controllers\Api\Members;

use App\Http\Controllers\Controller;
use App\Http\Resources\Groups\GroupAvailabilityScheduleResource;
use App\Models\Group;
use App\Models\GroupMembershipApplication;
use App\Services\Notifications\NotificationPreferenceSettingsService;
use App\Support\Integrations\MemberPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = Group::whereHas('memberships', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['memberships' => fn ($q) => $q->where('user_id', $request->user()->id)])
            ->orderBy('name')->orderBy('id')->paginate(MemberPagination::size($request));

        return response()->json(MemberPagination::payload($page, fn ($group) => $this->present($group, $request)));
    }

    public function show(Request $request, Group $group): JsonResponse
    {
        abort_if($group->isBanned($request->user()->id), 404);
        abort_unless($group->is_visible || $group->hasMember($request->user()->id), 404);

        return response()->json(['data' => $this->present($group, $request)]);
    }

    public function requests(Request $request): JsonResponse
    {
        $page = GroupMembershipApplication::where('user_id', $request->user()->id)->with('group')
            ->orderByDesc('id')->paginate(MemberPagination::size($request));

        return response()->json(MemberPagination::payload($page, fn ($application) => [
            ...$application->only(['id', 'status', 'answers', 'form_snapshot', 'review_reason']),
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'group' => $application->group?->only(['id', 'name', 'slug']),
        ]));
    }

    public function notifications(Request $request, Group $group, NotificationPreferenceSettingsService $preferences): JsonResponse
    {
        $membership = $group->memberships()->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['data' => ['enabled' => (bool) $membership->notifications_enabled,
            'notification_preferences' => $preferences->serializeGroupPreferences($request->user(), $group->id)]]);
    }

    public function availability(Request $request, Group $group): JsonResponse
    {
        abort_unless($group->featureEnabled('availability_scheduler_enabled'), 404);
        abort_if($group->isBanned($request->user()->id), 403);
        abort_unless($group->canUseAvailability($request->user()->id), 403);
        $schedule = $group->availabilitySchedules()->where('user_id', $request->user()->id)->with(['windows', 'exceptions'])->first();

        return response()->json(['data' => $schedule ? GroupAvailabilityScheduleResource::make($schedule)->resolve($request) : null]);
    }

    private function present(Group $group, Request $request): array
    {
        return [...$group->only(['id', 'name', 'slug', 'description', 'profile_picture_url', 'banner_image_url', 'datacenter', 'group_type', 'join_mode', 'is_visible', 'preferred_languages', 'primary_focuses', 'tags']),
            'membership' => $group->memberships()->where('user_id', $request->user()->id)->first()?->only(['role', 'joined_at', 'notifications_enabled']),
            'is_in_my_runs' => $request->user()->runListGroups()->whereKey($group->id)->exists(),
        ];
    }
}
