<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupAvailabilitySelectionRequest;
use App\Http\Requests\UpdateGroupAvailabilityScheduleRequest;
use App\Http\Requests\UpdateGroupAvailabilitySettingsRequest;
use App\Http\Resources\Groups\GroupAvailabilityScheduleResource;
use App\Http\Resources\Groups\GroupAvailabilitySelectionResource;
use App\Models\Group;
use App\Models\GroupAvailabilitySetting;
use App\Services\AuditLogger;
use App\Services\Groups\GroupAvailabilityOverviewService;
use App\Services\Groups\GroupAvailabilityScheduleService;
use App\Services\Groups\GroupAvailabilitySelectionService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class GroupAvailabilityController extends Controller
{
    public function __construct(
        private readonly GroupAvailabilityScheduleService $scheduleService,
        private readonly GroupAvailabilityOverviewService $overviewService,
        private readonly GroupAvailabilitySelectionService $selectionService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Group $group): Response
    {
        $group->loadMissing(['memberships', 'features', 'availabilitySettings']);

        $currentUserId = auth()->id();

        if (! $group->hasMember($currentUserId)) {
            abort(403);
        }

        abort_unless($group->featureEnabled('availability_scheduler_enabled'), 404);

        $minimumRole = $group->availabilityMinimumRole();
        abort_unless($group->canUseAvailability($currentUserId), 403);

        $schedule = $group->availabilitySchedules()
            ->where('user_id', $currentUserId)
            ->with(['windows', 'exceptions'])
            ->first();

        return Inertia::render('Dashboard/Groups/Availability', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'current_user_role' => $group->memberships
                    ->firstWhere('user_id', $currentUserId)
                    ?->role,
                'features' => $group->featureSettings(),
                'permissions' => [
                    'can_manage_group' => $group->isOwnedBy($currentUserId),
                    'can_manage_members' => $group->hasModeratorAccess($currentUserId),
                    'can_manage_discovery' => $group->hasAdminAccess($currentUserId),
                    'can_manage_activities' => $group->hasModeratorAccess($currentUserId),
                    'can_view_members' => true,
                    'can_review_membership_applications' => $group->usesMembershipApplications() && $group->hasModeratorAccess($currentUserId),
                    'can_manage_membership_application_form' => $group->usesMembershipApplications() && $group->hasAdminAccess($currentUserId),
                    'can_use_availability' => true,
                ],
            ],
            'availability_settings' => [
                'minimum_role' => $minimumRole,
            ],
            'schedule' => $schedule
                ? GroupAvailabilityScheduleResource::make($schedule)->resolve()
                : null,
            'overview' => $this->overviewService->build($group, CarbonImmutable::now()),
        ]);
    }

    public function updateSettings(UpdateGroupAvailabilitySettingsRequest $request, Group $group): RedirectResponse
    {
        $group->loadMissing(['memberships', 'features', 'availabilitySettings']);

        abort_unless($group->featureEnabled('availability_scheduler_enabled'), 404);

        if (! $group->hasAdminAccess(auth()->id())) {
            abort(403);
        }

        $originalRole = $group->availabilitySettings?->minimum_role
            ?? GroupAvailabilitySetting::MINIMUM_ROLE_MEMBER;
        $minimumRole = $request->validated('minimum_role');

        $group->availabilitySettings()->updateOrCreate([], [
            'minimum_role' => $minimumRole,
        ]);

        $versionKey = "group_availability_version:{$group->id}";
        Cache::forever($versionKey, ((int) Cache::get($versionKey, 0)) + 1);

        if ($originalRole !== $minimumRole) {
            $this->auditLogger->log(
                action: 'group.updated',
                severity: AuditSeverity::MODERATION_CHANGE,
                scopeType: AuditScope::GROUP,
                scopeId: $group->id,
                message: 'audit_log.events.group.updated',
                actor: auth()->user(),
                subject: $group,
                metadata: [
                    'changed_fields' => ['availability.minimum_role'],
                    'changes' => [
                        'availability.minimum_role' => [
                            'from' => $originalRole,
                            'to' => $minimumRole,
                        ],
                    ],
                ],
            );
        }

        return redirect()->back();
    }

    public function selection(
        GroupAvailabilitySelectionRequest $request,
        Group $group,
    ): GroupAvailabilitySelectionResource {
        $group->loadMissing(['memberships', 'features', 'availabilitySettings']);

        abort_unless($group->featureEnabled('availability_scheduler_enabled'), 404);

        if (! $group->canUseAvailability(auth()->id())) {
            abort(403);
        }

        return GroupAvailabilitySelectionResource::make($this->selectionService->build(
            $group,
            CarbonImmutable::parse($request->validated('starts_at')),
            CarbonImmutable::parse($request->validated('ends_at')),
        ));
    }

    public function updateSchedule(UpdateGroupAvailabilityScheduleRequest $request, Group $group): RedirectResponse
    {
        $group->loadMissing(['memberships', 'features', 'availabilitySettings']);

        abort_unless($group->featureEnabled('availability_scheduler_enabled'), 404);

        if (! $group->canUseAvailability(auth()->id())) {
            abort(403);
        }

        $this->scheduleService->save($group, $request->user(), $request->validated());

        return redirect()->back();
    }
}
