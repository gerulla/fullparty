<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithGroupActivityAttendees;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Groups\GroupActivityAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GroupActivityController extends Controller
{
    use InteractsWithGroupActivityAttendees;

    public function __construct(
        private readonly GroupActivityAuditService $activityAuditService,
    ) {}

    public function overview(Request $request, Group $group, Activity $activity, ?string $secretKey = null): Response
    {
        $this->ensureActivityBelongsToGroup($group, $activity);
        $group->loadMissing('memberships');

        if (!$this->canAccessOverview($request, $group, $activity, $secretKey)) {
            abort(404);
        }

        $activity->load($this->attendeeActivityRelations());

        return Inertia::render('Groups/Activities/Overview', [
            'group' => $this->serializePublicGroup($group),
            'activity' => $this->serializeAttendeeActivity($activity),
            'permissions' => [
                'can_apply' => $request->user() !== null,
                'can_manage' => $group->hasModeratorAccess($request->user()?->id),
            ],
        ]);
    }

    public function create(Group $group): Response
    {
        $group->loadMissing('memberships.user');
        $this->authorizeModeratorAccess($group);

        return Inertia::render('Dashboard/Groups/Activities/Create', [
            'group' => $this->buildDashboardGroupPayload($group),
            'activityTypes' => $this->availableActivityTypesForForm(),
            'organizerCharacters' => $this->organizerCharactersForUserIds($this->moderatorUserIds($group)),
        ]);
    }

    public function edit(Group $group, Activity $activity): Response
    {
        $group->loadMissing('memberships.user');
        $this->authorizeModeratorAccess($group);
        $this->ensureActivityBelongsToGroup($group, $activity);
        $this->ensureActivityIsMutable($activity);

        $activity->load([
            'activityType.currentPublishedVersion',
            'organizerCharacter.user',
        ]);

        $activityType = $activity->activityType;

        return Inertia::render('Dashboard/Groups/Activities/Edit', [
            'group' => $this->buildDashboardGroupPayload($group),
            'activity' => [
                'id' => $activity->id,
                'activity_type_id' => $activity->activity_type_id,
                'title' => $activity->title,
                'status' => $activity->status,
                'notes' => $activity->notes,
                'starts_at' => $activity->starts_at?->setTimezone('UTC')->format('Y-m-d\TH:i'),
                'duration_hours' => $activity->duration_hours,
                'target_prog_point_key' => $activity->target_prog_point_key,
                'is_public' => $activity->is_public,
                'needs_application' => $activity->needs_application,
                'organized_by_user_id' => $activity->organized_by_user_id,
                'organized_by_character_id' => $activity->organized_by_character_id,
            ],
            'activityTypes' => $activityType ? collect([$activityType])->map(fn (ActivityType $type) => $this->serializeActivityTypeForForm($type))->values() : [],
            'organizerCharacters' => $this->organizerCharactersForUserIds($this->moderatorUserIds($group)),
        ]);
    }

    public function index(Group $group): Response
    {
        $group->load([
            'memberships',
            'activities.organizer',
            'activities.organizerCharacter',
            'activities.activityType',
            'activities.activityTypeVersion',
            'activities.slots',
            'activities.applications',
            'activities.progressMilestones',
        ]);

        if (!$group->hasMember(auth()->id())) {
            abort(403);
        }

        $canManageActivities = $group->hasModeratorAccess(auth()->id());
        $visibleActivities = $canManageActivities
            ? $group->activities
            : $group->activities->where('is_public', true);

        return Inertia::render('Dashboard/Groups/Activities/Index', [
            'group' => $this->buildDashboardGroupPayload($group, $canManageActivities),
            'activityTypes' => $this->availableActivityTypesForForm(false),
            'activities' => $visibleActivities
                ->sortByDesc('updated_at')
                ->values()
                ->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'activity_type' => [
                        'id' => $activity->activityType?->id,
                        'slug' => $activity->activityType?->slug,
                        'draft_name' => $activity->activityType?->draft_name,
                    ],
                    'activity_type_version_id' => $activity->activity_type_version_id,
                    'title' => $activity->title,
                    'status' => $activity->status,
                    'starts_at' => $activity->starts_at?->toIso8601String(),
                    'duration_hours' => $activity->duration_hours,
                    'target_prog_point_key' => $activity->target_prog_point_key,
                    'notes' => $activity->notes,
                    'furthest_progress_key' => $activity->furthest_progress_key,
                    'is_public' => $activity->is_public,
                    'needs_application' => $activity->needs_application,
                    'organized_by' => $activity->organizer ? [
                        'id' => $activity->organizer->id,
                        'name' => $activity->organizer->name,
                        'avatar_url' => $activity->organizer->avatar_url,
                    ] : null,
                    'organized_by_character' => $activity->organizerCharacter ? [
                        'id' => $activity->organizerCharacter->id,
                        'user_id' => $activity->organizerCharacter->user_id,
                        'name' => $activity->organizerCharacter->name,
                        'avatar_url' => $activity->organizerCharacter->avatar_url,
                    ] : null,
                    'slot_count' => $activity->slots->count(),
                    'application_count' => $activity->applications->count(),
                    'progress_milestone_count' => $activity->progressMilestones->count(),
                    'created_at' => $activity->created_at?->toIso8601String(),
                    'updated_at' => $activity->updated_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request, Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);

        $validated = $request->validate($this->rules($group));
        $validated = $this->normalizeAndValidateOrganizerCharacter($validated);
        $validated = $this->normalizeStartsAt($validated);

        $activityType = ActivityType::query()
            ->with('currentPublishedVersion')
            ->findOrFail($validated['activity_type_id']);

        $activityTypeVersion = $activityType->currentPublishedVersion;

        if (!$activityType->is_active || !$activityTypeVersion) {
            abort(422, 'The selected activity type is not available.');
        }

        $validated = $this->normalizeAndValidateTargetProgPoint($validated, $activityTypeVersion);

        DB::transaction(function () use ($group, $activityType, $activityTypeVersion, $validated) {
            $activity = $group->activities()->create([
                'activity_type_id' => $activityType->id,
                'activity_type_version_id' => $activityTypeVersion->id,
                'organized_by_user_id' => $validated['organized_by_user_id'] ?? auth()->id(),
                'organized_by_character_id' => $validated['organized_by_character_id'] ?? null,
                'status' => $validated['status'],
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'starts_at' => $validated['starts_at'] ?? null,
                'duration_hours' => $validated['duration_hours'] ?? 2,
                'target_prog_point_key' => $validated['target_prog_point_key'] ?? null,
                'is_public' => $validated['is_public'] ?? true,
                'needs_application' => $validated['needs_application'] ?? true,
                'secret_key' => ($validated['is_public'] ?? true) ? null : Activity::generateSecretKey(),
            ]);

            $this->materializeSlots($activity, $activityTypeVersion);
            $this->materializeProgressMilestones($activity, $activityTypeVersion);
            $this->activityAuditService->logActivityCreated($activity, auth()->user());
        });

        return redirect()
            ->route('groups.dashboard.activities.index', $group)
            ->with('success', 'activity_created');
    }

    public function show(Group $group, Activity $activity): Response
    {
        $this->authorize('manageDashboard', [$activity, $group]);
        $group->loadMissing('memberships');

        return Inertia::render('Dashboard/Groups/Activities/Show', [
            'group' => $this->buildDashboardGroupPayload($group),
            'activity' => [
                'id' => $activity->id,
            ],
        ]);
    }

    public function update(Request $request, Group $group, Activity $activity): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);
        $this->ensureActivityBelongsToGroup($group, $activity);
        $this->ensureActivityIsMutable($activity);

        $validated = $request->validate($this->rules($group, false, true));
        $validated = $this->normalizeAndValidateOrganizerCharacter($validated);
        $validated = $this->normalizeStartsAt($validated);

        $activityTypeVersion = null;

        if ($activity->activityTypeVersion) {
            $activityTypeVersion = $activity->activityTypeVersion;
        }

        $validated = $this->normalizeAndValidateTargetProgPoint($validated, $activityTypeVersion);

        $original = $activity->only([
            'organized_by_user_id',
            'organized_by_character_id',
            'title',
            'description',
            'notes',
            'starts_at',
            'duration_hours',
            'target_prog_point_key',
            'is_public',
            'needs_application',
        ]);

        $activity->update([
            'organized_by_user_id' => $validated['organized_by_user_id'] ?? $activity->organized_by_user_id,
            'organized_by_character_id' => array_key_exists('organized_by_character_id', $validated)
                ? $validated['organized_by_character_id']
                : $activity->organized_by_character_id,
            'title' => $validated['title'] ?? $activity->title,
            'description' => $validated['description'] ?? $activity->description,
            'notes' => $validated['notes'] ?? $activity->notes,
            'starts_at' => $validated['starts_at'] ?? $activity->starts_at,
            'duration_hours' => $validated['duration_hours'] ?? $activity->duration_hours,
            'target_prog_point_key' => array_key_exists('target_prog_point_key', $validated)
                ? $validated['target_prog_point_key']
                : $activity->target_prog_point_key,
            'is_public' => $validated['is_public'] ?? $activity->is_public,
            'needs_application' => $validated['needs_application'] ?? $activity->needs_application,
            'secret_key' => ($validated['is_public'] ?? $activity->is_public)
                ? null
                : ($activity->secret_key ?: Activity::generateSecretKey()),
        ]);

        $changes = [];

        foreach ([
            'organized_by_user_id',
            'organized_by_character_id',
            'title',
            'description',
            'notes',
            'starts_at',
            'duration_hours',
            'target_prog_point_key',
            'is_public',
            'needs_application',
        ] as $field) {
            $old = $original[$field] ?? null;
            $new = $activity->{$field};

            if ($field === 'starts_at') {
                $old = $old?->toIso8601String();
                $new = $new?->toIso8601String();
            }

            if ($old !== $new) {
                $changes[$field] = [
                    'old' => $old,
                    'new' => $new,
                ];
            }
        }

        $this->activityAuditService->logActivityUpdated($activity, auth()->user(), $changes);

        return redirect()
            ->route('groups.dashboard.activities.show', [
                'group' => $group,
                'activity' => $activity,
            ])
            ->with('success', 'activity_updated');
    }

    public function destroy(Group $group, Activity $activity): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);
        $this->ensureActivityBelongsToGroup($group, $activity);
        $this->ensureActivityCanBeDeleted($activity);

        $this->activityAuditService->logActivityDeleted($group, $activity, auth()->user());
        $activity->delete();

        return redirect()
            ->route('groups.dashboard.activities.index', $group)
            ->with('success', 'activity_deleted');
    }

    public function cancel(Group $group, Activity $activity): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);
        $this->ensureActivityBelongsToGroup($group, $activity);
        $this->ensureActivityCanBeCancelled($activity);

        $previousStatus = $activity->status;

        $activity->update([
            'status' => Activity::STATUS_CANCELLED,
        ]);

        $this->activityAuditService->logActivityUpdated($activity, auth()->user(), [
            'status' => [
                'old' => $previousStatus,
                'new' => Activity::STATUS_CANCELLED,
            ],
        ]);

        return redirect()
            ->route('groups.dashboard.activities.show', [
                'group' => $group,
                'activity' => $activity,
            ])
            ->with('success', 'activity_cancelled');
    }

    public function publishRoster(Group $group, Activity $activity): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);
        $this->ensureActivityBelongsToGroup($group, $activity);
        $this->ensureActivityCanBeMarkedAssigned($activity);

        $previousStatus = $activity->status;

        $activity->update([
            'status' => Activity::STATUS_ASSIGNED,
        ]);

        // TODO: Notify affected users about their assigned roster positions.
        $this->activityAuditService->logActivityUpdated($activity, auth()->user(), [
            'status' => [
                'old' => $previousStatus,
                'new' => Activity::STATUS_ASSIGNED,
            ],
        ]);

        return redirect()
            ->route('groups.dashboard.activities.show', [
                'group' => $group,
                'activity' => $activity,
            ])
            ->with('success', 'activity_roster_published');
    }

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    private function rules(Group $group, bool $requireActivityType = true, bool $isUpdate = false): array
    {
        $moderatorIds = $group->memberships
            ->filter(fn (GroupMembership $membership) => in_array($membership->role, [
                GroupMembership::ROLE_OWNER,
                GroupMembership::ROLE_MODERATOR,
            ], true))
            ->pluck('user_id')
            ->all();

        $rules = [
            'organized_by_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::in($moderatorIds),
            ],
            'organized_by_character_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:characters,id',
            ],
            'status' => $isUpdate
                ? ['prohibited']
                : ['required', Rule::in([
                    Activity::STATUS_PLANNED,
                    Activity::STATUS_SCHEDULED,
                ])],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'starts_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i'],
            'duration_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'target_prog_point_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_public' => $isUpdate ? ['prohibited'] : ['sometimes', 'boolean'],
            'needs_application' => $isUpdate ? ['prohibited'] : ['sometimes', 'boolean'],
        ];

        $rules['activity_type_id'] = $requireActivityType
            ? ['required', 'integer', 'exists:activity_types,id']
            : ['prohibited'];

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeAndValidateOrganizerCharacter(array $validated): array
    {
        $characterId = $validated['organized_by_character_id'] ?? null;

        if (!$characterId) {
            return $validated;
        }

        $character = Character::query()->find($characterId);

        if (!$character) {
            abort(422, 'The selected organizer character is invalid.');
        }

        $organizerUserId = $validated['organized_by_user_id'] ?? auth()->id();

        if ($character->user_id !== (int) $organizerUserId) {
            abort(422, 'The selected organizer character must belong to the organizer user.');
        }

        $validated['organized_by_user_id'] = $organizerUserId;

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeAndValidateTargetProgPoint(array $validated, ?ActivityTypeVersion $activityTypeVersion = null): array
    {
        $targetProgPointKey = $validated['target_prog_point_key'] ?? null;

        if (!$targetProgPointKey) {
            return $validated;
        }

        $availableKeys = collect($activityTypeVersion?->prog_points ?? [])
            ->pluck('key')
            ->filter()
            ->all();

        if (!in_array($targetProgPointKey, $availableKeys, true)) {
            abort(422, 'The selected target prog point is invalid for this activity type.');
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeStartsAt(array $validated): array
    {
        $startsAt = $validated['starts_at'] ?? null;

        if (!$startsAt) {
            return $validated;
        }

        $validated['starts_at'] = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $startsAt, 'UTC')
            ->utc();

        return $validated;
    }

    private function materializeSlots(Activity $activity, ActivityTypeVersion $activityTypeVersion): void
    {
        $slotDefinitions = $activityTypeVersion->slot_schema ?? [];
        $groups = $activityTypeVersion->layout_schema['groups'] ?? [];
        $benchSize = max(0, (int) ($activityTypeVersion->bench_size ?? 0));
        $sortOrder = 1;

        foreach ($groups as $groupDefinition) {
            $groupKey = (string) ($groupDefinition['key'] ?? 'group');
            $groupLabel = is_array($groupDefinition['label'] ?? null) ? $groupDefinition['label'] : ['en' => $groupKey];
            $size = max(1, (int) ($groupDefinition['size'] ?? 1));

            for ($position = 1; $position <= $size; $position++) {
                $slot = $activity->slots()->create([
                    'group_key' => $groupKey,
                    'group_label' => $groupLabel,
                    'slot_key' => sprintf('%s-slot-%d', $groupKey, $position),
                    'slot_label' => ['en' => sprintf('%s %d', $groupLabel['en'] ?? $groupKey, $position)],
                    'position_in_group' => $position,
                    'sort_order' => $sortOrder,
                ]);

                foreach ($slotDefinitions as $fieldDefinition) {
                    $slot->fieldValues()->create([
                        'field_key' => (string) ($fieldDefinition['key'] ?? ''),
                        'field_label' => is_array($fieldDefinition['label'] ?? null) ? $fieldDefinition['label'] : ['en' => (string) ($fieldDefinition['key'] ?? '')],
                        'field_type' => (string) ($fieldDefinition['type'] ?? 'text'),
                        'source' => $fieldDefinition['source'] ?? null,
                        'value' => null,
                    ]);
                }

                $sortOrder++;
            }
        }

        for ($position = 1; $position <= $benchSize; $position++) {
            $activity->slots()->create([
                'group_key' => ActivitySlotBench::GROUP_KEY,
                'group_label' => ['en' => 'Bench'],
                'slot_key' => sprintf('%s-slot-%d', ActivitySlotBench::GROUP_KEY, $position),
                'slot_label' => ['en' => sprintf('Bench %d', $position)],
                'position_in_group' => $position,
                'sort_order' => $sortOrder,
            ]);

            $sortOrder++;
        }
    }

    private function materializeProgressMilestones(Activity $activity, ActivityTypeVersion $activityTypeVersion): void
    {
        $milestones = $activityTypeVersion->progress_schema['milestones'] ?? [];

        foreach ($milestones as $index => $milestoneDefinition) {
            $activity->progressMilestones()->create([
                'milestone_key' => (string) ($milestoneDefinition['key'] ?? ('milestone-'.($index + 1))),
                'milestone_label' => is_array($milestoneDefinition['label'] ?? null) ? $milestoneDefinition['label'] : ['en' => (string) ($milestoneDefinition['key'] ?? 'Milestone')],
                'sort_order' => (int) ($milestoneDefinition['order'] ?? $index + 1),
                'kills' => 0,
                'best_progress_percent' => null,
                'source' => null,
                'notes' => null,
            ]);
        }
    }

    private function authorizeModeratorAccess(Group $group): void
    {
        if (!$group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }

    private function ensureActivityIsMutable(Activity $activity): void
    {
        if ($activity->isArchived()) {
            abort(403);
        }
    }

    private function ensureActivityCanBeCancelled(Activity $activity): void
    {
        if (!$activity->canBeCancelled()) {
            abort(403);
        }
    }

    private function ensureActivityCanBeDeleted(Activity $activity): void
    {
        if (!$activity->canBeDeleted()) {
            abort(403);
        }
    }

    private function ensureActivityCanBeMarkedAssigned(Activity $activity): void
    {
        if (!$activity->canBeMarkedAssigned()) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardGroupPayload(Group $group, ?bool $canManageActivities = null): array
    {
        $canModerateGroup = $group->hasModeratorAccess(auth()->id());

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'current_user_role' => $group->memberships
                ->firstWhere('user_id', auth()->id())
                ?->role,
            'permissions' => [
                'can_manage_group' => $group->isOwnedBy(auth()->id()),
                'can_manage_members' => $canModerateGroup,
                'can_manage_activities' => $canManageActivities ?? $canModerateGroup,
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function moderatorUserIds(Group $group): array
    {
        return $group->memberships
            ->filter(fn (GroupMembership $membership) => in_array($membership->role, [
                GroupMembership::ROLE_OWNER,
                GroupMembership::ROLE_MODERATOR,
            ], true))
            ->pluck('user_id')
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function organizerCharactersForUserIds(array $userIds)
    {
        return Character::query()
            ->with('user:id,name')
            ->whereIn('user_id', $userIds)
            ->orderBy('name')
            ->get()
            ->map(fn (Character $character) => [
                'id' => $character->id,
                'user_id' => $character->user_id,
                'name' => $character->name,
                'user_name' => $character->user?->name,
                'avatar_url' => $character->avatar_url,
                'world' => $character->world,
            ])
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function availableActivityTypesForForm(bool $includeProgPoints = true)
    {
        return ActivityType::query()
            ->with('currentPublishedVersion')
            ->where('is_active', true)
            ->whereNotNull('current_published_version_id')
            ->orderBy('slug')
            ->get()
            ->map(fn (ActivityType $activityType) => $this->serializeActivityTypeForForm($activityType, $includeProgPoints))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeActivityTypeForForm(ActivityType $activityType, bool $includeProgPoints = true): array
    {
        return [
            'id' => $activityType->id,
            'slug' => $activityType->slug,
            'draft_name' => $activityType->draft_name,
            'current_published_version_id' => $activityType->current_published_version_id,
            'slot_count' => collect($activityType->currentPublishedVersion?->layout_schema['groups'] ?? [])
                ->sum(fn (array $groupDefinition) => (int) ($groupDefinition['size'] ?? 0)),
            'prog_points' => $includeProgPoints
                ? ($activityType->currentPublishedVersion?->prog_points ?? [])
                : [],
        ];
    }
}
