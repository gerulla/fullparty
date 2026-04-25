<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GroupActivityController extends Controller
{
    public function overview(Request $request, Group $group, Activity $activity, ?string $secretKey = null): Response
    {
        $this->ensureActivityBelongsToGroup($group, $activity);
        $group->loadMissing('memberships');

        if (!$this->canAccessOverview($request, $group, $activity, $secretKey)) {
            abort(404);
        }

        $activity->load([
            'organizer',
            'organizerCharacter',
            'activityType',
            'activityTypeVersion',
        ]);

        return Inertia::render('Groups/Activities/Overview', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'is_public' => $group->is_public,
            ],
            'activity' => [
                'id' => $activity->id,
                'activity_type' => [
                    'id' => $activity->activityType?->id,
                    'slug' => $activity->activityType?->slug,
                    'draft_name' => $activity->activityType?->draft_name,
                ],
                'activity_type_version_id' => $activity->activity_type_version_id,
                'title' => $activity->title,
                'description' => $activity->description,
                'notes' => $activity->notes,
                'status' => $activity->status,
                'starts_at' => $activity->starts_at?->toIso8601String(),
                'duration_hours' => $activity->duration_hours,
                'target_prog_point_key' => $activity->target_prog_point_key,
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
            ],
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
        });

        return redirect()
            ->route('groups.dashboard.activities.index', $group)
            ->with('success', 'activity_created');
    }

    public function show(Group $group, Activity $activity): Response
    {
        $this->ensureActivityBelongsToGroup($group, $activity);
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);

        $activity->load([
            'organizer',
            'organizerCharacter',
            'activityType',
            'activityTypeVersion',
            'slots.fieldValues',
            'applications.user',
            'progressMilestones',
        ]);

        return Inertia::render('Dashboard/Groups/Activities/Show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'current_user_role' => $group->memberships
                    ->firstWhere('user_id', auth()->id())
                    ?->role,
                'permissions' => [
                    'can_manage_activities' => $group->hasModeratorAccess(auth()->id()),
                ],
            ],
            'activity' => [
                'id' => $activity->id,
                'activity_type' => [
                    'id' => $activity->activityType?->id,
                    'slug' => $activity->activityType?->slug,
                    'draft_name' => $activity->activityType?->draft_name,
                ],
                'activity_type_version_id' => $activity->activity_type_version_id,
                'title' => $activity->title,
                'description' => $activity->description,
                'notes' => $activity->notes,
                'status' => $activity->status,
                'starts_at' => $activity->starts_at?->toIso8601String(),
                'duration_hours' => $activity->duration_hours,
                'target_prog_point_key' => $activity->target_prog_point_key,
                'furthest_progress_key' => $activity->furthest_progress_key,
                'is_public' => $activity->is_public,
                'needs_application' => $activity->needs_application,
                'secret_key' => $activity->secret_key,
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
                'slots' => $activity->slots->map(fn ($slot) => [
                    'id' => $slot->id,
                    'group_key' => $slot->group_key,
                    'group_label' => $slot->group_label,
                    'slot_key' => $slot->slot_key,
                    'slot_label' => $slot->slot_label,
                    'position_in_group' => $slot->position_in_group,
                    'sort_order' => $slot->sort_order,
                    'assigned_character_id' => $slot->assigned_character_id,
                    'field_values' => $slot->fieldValues->map(fn ($fieldValue) => [
                        'id' => $fieldValue->id,
                        'field_key' => $fieldValue->field_key,
                        'field_label' => $fieldValue->field_label,
                        'field_type' => $fieldValue->field_type,
                        'source' => $fieldValue->source,
                        'value' => $fieldValue->value,
                    ])->values(),
                ])->values(),
                'progress_milestones' => $activity->progressMilestones->map(fn ($milestone) => [
                    'id' => $milestone->id,
                    'milestone_key' => $milestone->milestone_key,
                    'milestone_label' => $milestone->milestone_label,
                    'sort_order' => $milestone->sort_order,
                    'kills' => $milestone->kills,
                    'best_progress_percent' => $milestone->best_progress_percent,
                    'source' => $milestone->source,
                    'notes' => $milestone->notes,
                ])->values(),
                'applications' => $activity->applications->map(fn ($application) => [
                    'id' => $application->id,
                    'user' => $application->user ? [
                        'id' => $application->user->id,
                        'name' => $application->user->name,
                        'avatar_url' => $application->user->avatar_url,
                    ] : null,
                    'status' => $application->status,
                    'submitted_at' => $application->submitted_at?->toIso8601String(),
                ])->values(),
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

        $activity->update([
            'organized_by_user_id' => $validated['organized_by_user_id'] ?? $activity->organized_by_user_id,
            'organized_by_character_id' => array_key_exists('organized_by_character_id', $validated)
                ? $validated['organized_by_character_id']
                : $activity->organized_by_character_id,
            'status' => $validated['status'],
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
        $this->ensureActivityIsMutable($activity);

        $activity->delete();

        return redirect()
            ->route('groups.dashboard.activities.index', $group)
            ->with('success', 'activity_deleted');
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
            'status' => ['required', Rule::in(Activity::STATUSES)],
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

    private function ensureActivityBelongsToGroup(Group $group, Activity $activity): void
    {
        if ($activity->group_id !== $group->id) {
            abort(404);
        }
    }

    private function ensureActivityIsMutable(Activity $activity): void
    {
        if ($activity->status === Activity::STATUS_COMPLETE) {
            abort(403);
        }
    }

    private function canAccessOverview(Request $request, Group $group, Activity $activity, ?string $secretKey): bool
    {
        if ($activity->is_public) {
            if ($group->is_public) {
                return true;
            }

            return $group->hasMember($request->user()?->id);
        }

        return filled($secretKey)
            && filled($activity->secret_key)
            && hash_equals((string) $activity->secret_key, (string) $secretKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardGroupPayload(Group $group, ?bool $canManageActivities = null): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'current_user_role' => $group->memberships
                ->firstWhere('user_id', auth()->id())
                ?->role,
            'permissions' => [
                'can_manage_activities' => $canManageActivities ?? $group->hasModeratorAccess(auth()->id()),
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
