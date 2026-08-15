<?php

namespace App\Http\Controllers;

use App\DTOs\QuotaCheck;
use App\Http\Controllers\Concerns\InteractsWithGroupActivityAttendees;
use App\Http\Resources\GroupQuickCreateShortcutResource;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotCompositionHint;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Services\Groups\ActivityCancellationService;
use App\Services\Groups\ActivityIndexItemSerializer;
use App\Services\Groups\ActivityRosterSummaryPresetBuilder;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Notifications\AssignmentNotificationService;
use App\Services\Notifications\GroupUpdateNotificationService;
use App\Services\Quotas\QuotaService;
use App\Support\ActivityCompositionPresets;
use App\Support\Input\RequestTextInputSanitizer;
use App\Support\Quotas\QuotaKey;
use App\Support\Seo\ServerMeta;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GroupActivityController extends Controller
{
    use InteractsWithGroupActivityAttendees;

    public function __construct(
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly ActivityCancellationService $activityCancellationService,
        private readonly GroupUpdateNotificationService $groupUpdateNotificationService,
        private readonly RequestTextInputSanitizer $requestTextInputSanitizer,
        private readonly ServerMeta $serverMeta,
        private readonly QuotaService $quotaService,
    ) {}

    public function overview(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlotSerializer $slotSerializer,
        ActivityRosterSummaryPresetBuilder $rosterSummaryPresetBuilder,
        ActivitySlotFieldDefinitionBuilder $fieldDefinitionBuilder,
        ?string $secretKey = null,
    ): Response {
        $this->ensureActivityBelongsToGroup($group, $activity);
        $group->loadMissing('memberships');

        if (! $this->canAccessOverview($request, $group, $activity, $secretKey)) {
            abort(404);
        }

        $activity->load(array_merge($this->attendeeActivityRelations(), [
            'slots.assignedCharacter',
            'slots.assignments.application.answers',
            'slots.compositionHints.characterClass',
            'slots.fieldValues',
        ]));
        $activity->loadCount([
            'slots' => fn ($query) => $query->where('slot_kind', '!=', ActivitySlot::SLOT_KIND_FILL_IN),
            'slots as assigned_slot_count' => fn ($query) => $query
                ->where('slot_kind', '!=', ActivitySlot::SLOT_KIND_FILL_IN)
                ->whereNotNull('assigned_character_id'),
            'applications as pending_application_count' => fn ($query) => $query->where('status', ActivityApplication::STATUS_PENDING),
        ]);

        $canUseParticipationFlow = $this->canUseActivityParticipationFlow($group, $activity, $request->user()?->id);

        $permissions = [
            'can_apply' => $request->user() !== null && $canUseParticipationFlow,
            'can_apply_as_guest' => $request->user() === null
                && $activity->is_public
                && $activity->allow_guest_applications
                && $canUseParticipationFlow,
            'can_manage' => $group->hasModeratorAccess($request->user()?->id),
            'can_self_assign' => ! $activity->needs_application
                && $request->user() !== null
                && $canUseParticipationFlow
                && ! $activity->isArchived(),
        ];

        $props = [
            'group' => $group->hasMember($request->user()?->id)
                ? $this->buildDashboardGroupPayload($group)
                : $this->serializePublicGroup($group),
            'activity' => $this->serializeAttendeeActivity(
                $activity,
                $slotSerializer,
                $rosterSummaryPresetBuilder->buildForActivity($activity),
            ),
            'secretKey' => null,
            'permissions' => $permissions,
        ];

        if (! $activity->needs_application) {
            $props['slotFieldDefinitions'] = $fieldDefinitionBuilder->build($activity->activityTypeVersion, $group->id);
            $props['selfAssignmentCharacters'] = $request->user()
                ? $this->selfAssignmentCharactersForUser($request->user()->id)
                : [];
        }

        return Inertia::render(
            $activity->needs_application
                ? 'Groups/Activities/Overview'
                : 'Groups/Activities/NonApplicationOverview',
            $props,
        )->withViewData('serverMeta', $this->serverMeta->activity($group, $activity));
    }

    public function create(Request $request, Group $group): Response
    {
        $group->loadMissing('memberships.user');
        $this->authorizeModeratorAccess($group);

        return Inertia::render('Dashboard/Groups/Activities/Create', [
            'group' => $this->buildDashboardGroupPayload($group),
            'activityTypes' => $this->availableActivityTypesForForm(),
            'organizerCharacters' => $this->organizerCharactersForUserIds($this->moderatorUserIds($group)),
            'activityOptions' => $this->activityOptionsForForm(),
            'prefilledStartsAt' => $this->normalizePrefilledStartsAt($request->query('starts_at')),
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
                'datacenter' => $activity->datacenter,
                'intensity' => $activity->intensity,
                'min_item_level' => $activity->min_item_level,
                'beginner_friendly' => $activity->beginner_friendly,
                'run_style' => $activity->run_style,
                'target_prog_point_key' => $activity->target_prog_point_key,
                'is_public' => $activity->is_public,
                'needs_application' => $activity->needs_application,
                'allow_guest_applications' => $activity->allow_guest_applications,
                'organized_by_user_id' => $activity->organized_by_user_id,
                'organized_by_character_id' => $activity->organized_by_character_id,
            ],
            'activityTypes' => $activityType ? collect([$activityType])->map(fn (ActivityType $type) => $this->serializeActivityTypeForForm($type))->values() : [],
            'organizerCharacters' => $this->organizerCharactersForUserIds($this->moderatorUserIds($group)),
            'activityOptions' => $this->activityOptionsForForm(),
        ]);
    }

    public function index(Group $group, ActivityIndexItemSerializer $serializer): Response
    {
        $group->load([
            'memberships',
            'activities.group',
            'activities.organizer',
            'activities.organizerCharacter',
            'activities.activityType',
            'activities.activityTypeVersion',
            'activities.slots',
            'activities.applications',
            'activities.progressMilestones',
            'quickCreateShortcuts',
        ]);

        $currentUserId = auth()->id();
        $isMember = $group->hasMember($currentUserId);

        if (! $isMember && ! $group->allowsPublicDashboardAccess()) {
            abort(404);
        }

        $canManageActivities = $isMember && $group->hasModeratorAccess($currentUserId);
        $visibleActivities = $canManageActivities
            ? $group->activities
            : $group->activities
                ->reject(fn (Activity $activity) => Activity::isModeratorOnlyStatus($activity->status));

        return Inertia::render('Dashboard/Groups/Activities/Index', [
            'group' => $this->buildDashboardGroupPayload($group, $canManageActivities),
            'activityTypes' => $this->availableActivityTypesForForm(false),
            'quickCreateShortcuts' => GroupQuickCreateShortcutResource::collection(
                $group->resolvedQuickCreateShortcuts(),
            )->resolve(),
            'activities' => $visibleActivities
                ->sortByDesc('updated_at')
                ->values()
                ->map(fn (Activity $activity): array => $serializer->serialize(
                    $activity,
                    $currentUserId,
                    $canManageActivities,
                )),
        ]);
    }

    public function store(Request $request, Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);
        $this->sanitizeActivityInput($request);

        $validated = $request->validate($this->rules($group));
        $validated = $this->normalizeAndValidateOrganizerCharacter($validated);
        $validated = $this->normalizeAndValidateStartsAt($validated);

        $activityType = ActivityType::query()
            ->with('currentPublishedVersion')
            ->findOrFail($validated['activity_type_id']);

        $activityTypeVersion = $activityType->currentPublishedVersion;

        if (! $activityType->is_active || ! $activityTypeVersion) {
            abort(422, 'The selected activity type is not available.');
        }

        $validated = $this->normalizeAndValidateTargetProgPoint($validated, $activityTypeVersion);

        $isPublic = $validated['is_public'] ?? true;

        $quotaChecks = [
            new QuotaCheck(QuotaKey::FUTURE_RUNS, $group),
            new QuotaCheck(QuotaKey::RUNS_PER_DAY, $group, [
                'starts_at' => $validated['starts_at'] ?? null,
            ]),
        ];

        $activity = $this->quotaService->run($quotaChecks, function () use ($group, $activityType, $activityTypeVersion, $validated, $isPublic) {
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
                'duration_hours' => $validated['duration_hours'] ?? Activity::DEFAULT_DURATION_HOURS,
                'datacenter' => $validated['datacenter'] ?? $group->datacenter,
                'intensity' => $validated['intensity'] ?? Activity::INTENSITY_CASUAL,
                'min_item_level' => array_key_exists('min_item_level', $validated)
                    ? $validated['min_item_level']
                    : $activityTypeVersion->default_min_item_level,
                'beginner_friendly' => $validated['beginner_friendly'] ?? false,
                'run_style' => $validated['run_style'] ?? Activity::RUN_STYLE_PROGRESSION,
                'target_prog_point_key' => $validated['target_prog_point_key'] ?? null,
                'is_public' => $isPublic,
                'needs_application' => $validated['needs_application'] ?? true,
                'allow_guest_applications' => $isPublic && ($validated['allow_guest_applications'] ?? false),
                'secret_key' => null,
            ]);

            $this->materializeSlots($activity, $activityTypeVersion);
            $this->materializeProgressMilestones($activity, $activityTypeVersion);
            $this->activityAuditService->logActivityCreated($activity, auth()->user());

            return $activity;
        });

        $this->groupUpdateNotificationService->notifyRunCreated(
            $activity->fresh('group'),
            auth()->user(),
        );

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
        $this->sanitizeActivityInput($request);

        $validated = $request->validate($this->rules($group, false, true));
        $validated = $this->normalizeAndValidateOrganizerCharacter($validated);
        $validated = $this->normalizeAndValidateStartsAt($validated);

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
            'datacenter',
            'intensity',
            'min_item_level',
            'beginner_friendly',
            'run_style',
            'target_prog_point_key',
            'is_public',
            'needs_application',
            'allow_guest_applications',
        ]);
        $nextIsPublic = $validated['is_public'] ?? $activity->is_public;

        $nextStartsAt = $validated['starts_at'] ?? $activity->starts_at;
        $updateActivity = function () use ($activity, $validated, $nextIsPublic, $nextStartsAt): void {
            $activity->update([
                'organized_by_user_id' => $validated['organized_by_user_id'] ?? $activity->organized_by_user_id,
                'organized_by_character_id' => array_key_exists('organized_by_character_id', $validated)
                    ? $validated['organized_by_character_id']
                    : $activity->organized_by_character_id,
                'title' => $validated['title'] ?? $activity->title,
                'description' => $validated['description'] ?? $activity->description,
                'notes' => $validated['notes'] ?? $activity->notes,
                'starts_at' => $nextStartsAt,
                'duration_hours' => $validated['duration_hours'] ?? $activity->duration_hours,
                'datacenter' => $validated['datacenter'] ?? $activity->datacenter,
                'intensity' => $validated['intensity'] ?? $activity->intensity,
                'min_item_level' => array_key_exists('min_item_level', $validated)
                    ? $validated['min_item_level']
                    : $activity->min_item_level,
                'beginner_friendly' => $validated['beginner_friendly'] ?? $activity->beginner_friendly,
                'run_style' => $validated['run_style'] ?? $activity->run_style,
                'target_prog_point_key' => array_key_exists('target_prog_point_key', $validated)
                    ? $validated['target_prog_point_key']
                    : $activity->target_prog_point_key,
                'is_public' => $nextIsPublic,
                'needs_application' => $validated['needs_application'] ?? $activity->needs_application,
                'allow_guest_applications' => $nextIsPublic && ($validated['allow_guest_applications'] ?? $activity->allow_guest_applications),
                'secret_key' => null,
            ]);
        };

        $scheduledDayChanged = $this->quotaService->runDay($group, $activity->starts_at)
            !== $this->quotaService->runDay($group, $nextStartsAt);

        if ($scheduledDayChanged && $nextStartsAt !== null) {
            $this->quotaService->run([
                new QuotaCheck(QuotaKey::RUNS_PER_DAY, $group, [
                    'starts_at' => $nextStartsAt,
                    'exclude_activity_id' => $activity->id,
                ]),
            ], $updateActivity);
        } else {
            $updateActivity();
        }

        $changes = [];

        foreach ([
            'organized_by_user_id',
            'organized_by_character_id',
            'title',
            'description',
            'notes',
            'starts_at',
            'duration_hours',
            'datacenter',
            'intensity',
            'min_item_level',
            'beginner_friendly',
            'run_style',
            'target_prog_point_key',
            'is_public',
            'needs_application',
            'allow_guest_applications',
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

    public function cancel(Request $request, Group $group, Activity $activity): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);
        $this->ensureActivityBelongsToGroup($group, $activity);
        $this->ensureActivityCanBeCancelled($activity);
        $this->requestTextInputSanitizer->sanitize($request, [], ['reason']);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:'.ActivityApplication::REVIEW_REASON_MAX_LENGTH],
        ]);

        $previousStatus = $activity->status;
        $reason = filled($validated['reason'] ?? null)
            ? trim((string) $validated['reason'])
            : null;

        $this->activityCancellationService->cancel($activity, auth()->user(), $reason);

        $changes = [
            'status' => [
                'old' => $previousStatus,
                'new' => Activity::STATUS_CANCELLED,
            ],
        ];

        if ($reason !== null) {
            $changes['review_reason'] = [
                'old' => null,
                'new' => $reason,
            ];
        }

        $this->activityAuditService->logActivityUpdated($activity, auth()->user(), $changes);

        return redirect()
            ->route('groups.dashboard.activities.show', [
                'group' => $group,
                'activity' => $activity,
            ])
            ->with('success', 'activity_cancelled');
    }

    public function schedule(Group $group, Activity $activity): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);
        $this->ensureActivityBelongsToGroup($group, $activity);
        $this->ensureActivityCanBeScheduled($activity);

        $previousStatus = $activity->status;

        $activity->update([
            'status' => Activity::STATUS_SCHEDULED,
        ]);

        $this->groupUpdateNotificationService->notifyRunScheduled(
            $activity->fresh('group'),
            auth()->user(),
        );

        $this->activityAuditService->logActivityUpdated($activity, auth()->user(), [
            'status' => [
                'old' => $previousStatus,
                'new' => Activity::STATUS_SCHEDULED,
            ],
        ]);

        return redirect()
            ->route('groups.dashboard.activities.show', [
                'group' => $group,
                'activity' => $activity,
            ])
            ->with('success', 'activity_scheduled');
    }

    public function publishRoster(
        Group $group,
        Activity $activity,
        AssignmentNotificationService $assignmentNotificationService,
    ): RedirectResponse {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);
        $this->ensureActivityBelongsToGroup($group, $activity);
        $this->ensureActivityCanBeMarkedAssigned($activity);

        $previousStatus = $activity->status;

        $activity->update([
            'status' => Activity::STATUS_ASSIGNED,
        ]);

        $assignmentNotificationService->notifyRosterPublished(
            $activity->fresh(['group', 'applications.user', 'applications.selectedCharacter', 'slots']),
            auth()->user(),
        );

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
     * @return array<string, array<int, ValidationRule|string>>
     */
    private function rules(Group $group, bool $requireActivityType = true, bool $isUpdate = false): array
    {
        $moderatorIds = $group->memberships
            ->filter(fn (GroupMembership $membership) => in_array($membership->role, [
                GroupMembership::ROLE_OWNER,
                GroupMembership::ROLE_ADMIN,
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
                    Activity::STATUS_DRAFT,
                    Activity::STATUS_SCHEDULED,
                ])],
            'title' => ['sometimes', 'nullable', 'string', 'max:'.Activity::TITLE_MAX_LENGTH],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.Activity::DESCRIPTION_MAX_LENGTH],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.Activity::NOTES_MAX_LENGTH],
            'starts_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i'],
            'duration_hours' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:'.Activity::DURATION_MIN_HOURS,
                'max:'.Activity::DURATION_MAX_HOURS,
                'multiple_of:'.Activity::DURATION_STEP_HOURS,
            ],
            'datacenter' => ['sometimes', 'string', Rule::in(config('datacenters.values', []))],
            'intensity' => ['sometimes', 'string', Rule::in(Activity::INTENSITIES)],
            'min_item_level' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'beginner_friendly' => ['sometimes', 'boolean'],
            'run_style' => ['sometimes', 'string', Rule::in(Activity::RUN_STYLES)],
            'target_prog_point_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_public' => ['sometimes', 'boolean'],
            'needs_application' => $isUpdate ? ['prohibited'] : ['sometimes', 'boolean'],
            'allow_guest_applications' => ['sometimes', 'boolean'],
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

        if (! $characterId) {
            return $validated;
        }

        $character = Character::query()->find($characterId);

        if (! $character) {
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

        if (! $targetProgPointKey) {
            return $validated;
        }

        $availableKeys = collect($activityTypeVersion?->prog_points ?? [])
            ->pluck('key')
            ->filter()
            ->all();

        if (! in_array($targetProgPointKey, $availableKeys, true)) {
            abort(422, 'The selected target prog point is invalid for this activity type.');
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeAndValidateStartsAt(array $validated): array
    {
        $startsAt = $validated['starts_at'] ?? null;

        if (! $startsAt) {
            return $validated;
        }

        $normalizedStartsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $startsAt, 'UTC')
            ->utc();

        if ($normalizedStartsAt->lt(CarbonImmutable::now('UTC')->startOfMinute())) {
            throw ValidationException::withMessages([
                'starts_at' => __('groups.activities.create.validation.starts_at_not_past'),
            ]);
        }

        $validated['starts_at'] = $normalizedStartsAt;

        return $validated;
    }

    private function normalizePrefilledStartsAt(mixed $startsAt): ?string
    {
        if (! is_string($startsAt) || blank($startsAt)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d\TH:i', trim($startsAt), 'UTC')
                ?->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitizeActivityInput(Request $request): void
    {
        $this->requestTextInputSanitizer->sanitize(
            $request,
            ['title'],
            ['description', 'notes'],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selfAssignmentCharactersForUser(int $userId): array
    {
        return Character::query()
            ->with([
                'user:id,name,avatar_url',
                'classes:id,name,shorthand,role,icon_url,flaticon_url',
                'phantomJobs:id,name,icon_url,transparent_icon_url,sprite_url',
            ])
            ->where('user_id', $userId)
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get()
            ->map(fn (Character $character) => [
                'id' => $character->id,
                'name' => $character->name,
                'avatar_url' => $character->avatar_url,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
                'user' => $character->user ? [
                    'id' => $character->user->id,
                    'name' => $character->user->name,
                    'avatar_url' => $character->user->avatar_url,
                ] : null,
                'character_class_ids' => $character->classes
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->values()
                    ->all(),
                'phantom_job_ids' => $character->phantomJobs
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
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
            $compositionHints = $this->compositionHintsByPosition($groupDefinition);

            for ($position = 1; $position <= $size; $position++) {
                $slot = $activity->slots()->create([
                    'slot_kind' => ActivitySlot::SLOT_KIND_ROSTER,
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

                $this->materializeCompositionHints($slot, $compositionHints->get($position, []));

                $sortOrder++;
            }
        }

        for ($position = 1; $position <= $benchSize; $position++) {
            $activity->slots()->create([
                'slot_kind' => ActivitySlot::SLOT_KIND_BENCH,
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

    /**
     * @param  array<string, mixed>  $groupDefinition
     * @return Collection<int, array<int, array<string, mixed>>>
     */
    private function compositionHintsByPosition(array $groupDefinition): Collection
    {
        return collect($groupDefinition['composition_hints'] ?? [])
            ->filter(fn ($hint): bool => is_array($hint))
            ->mapWithKeys(function (array $hint): array {
                $position = (int) ($hint['position'] ?? 0);

                if ($position < 1) {
                    return [];
                }

                return [$position => is_array($hint['accepts'] ?? null) ? $hint['accepts'] : []];
            });
    }

    /**
     * @param  array<int, array<string, mixed>>  $accepts
     */
    private function materializeCompositionHints(ActivitySlot $slot, array $accepts): void
    {
        foreach (array_values($accepts) as $index => $accept) {
            if (! is_array($accept)) {
                continue;
            }

            $type = (string) ($accept['type'] ?? '');
            $key = (string) ($accept['key'] ?? '');

            if ($key === '' || ! in_array($type, [ActivitySlotCompositionHint::TYPE_ROLE, ActivitySlotCompositionHint::TYPE_CLASS], true)) {
                continue;
            }

            $characterClass = $type === ActivitySlotCompositionHint::TYPE_CLASS
                ? $this->resolveCharacterClassHint($key)
                : null;

            $slot->compositionHints()->create([
                'hint_type' => $type,
                'hint_key' => $key,
                'role_key' => $type === ActivitySlotCompositionHint::TYPE_ROLE
                    ? $key
                    : $this->roleKeyForCharacterClassHint($characterClass),
                'character_class_id' => $characterClass?->id,
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function resolveCharacterClassHint(string $key): ?CharacterClass
    {
        static $classesByShorthand = null;

        $classesByShorthand ??= CharacterClass::query()
            ->get(['id', 'name', 'shorthand', 'role'])
            ->keyBy(fn (CharacterClass $characterClass): string => strtolower($characterClass->shorthand));

        return $classesByShorthand->get(strtolower($key));
    }

    private function roleKeyForCharacterClassHint(?CharacterClass $characterClass): ?string
    {
        if (! $characterClass) {
            return null;
        }

        $role = strtolower($characterClass->role);

        if ($role === ActivityCompositionPresets::ROLE_TANK) {
            return ActivityCompositionPresets::ROLE_TANK;
        }

        if ($role === ActivityCompositionPresets::ROLE_HEALER) {
            return ActivityCompositionPresets::ROLE_HEALER;
        }

        return ActivityCompositionPresets::ROLE_DPS;
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
        if (! $group->hasModeratorAccess(auth()->id())) {
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
        if (! $activity->canBeCancelled()) {
            abort(403);
        }
    }

    private function ensureActivityCanBeDeleted(Activity $activity): void
    {
        if (! $activity->canBeDeleted()) {
            abort(403);
        }
    }

    private function ensureActivityCanBeMarkedAssigned(Activity $activity): void
    {
        if (! $activity->canBeMarkedAssigned()) {
            abort(403);
        }
    }

    private function ensureActivityCanBeScheduled(Activity $activity): void
    {
        if (! $activity->canBeScheduled()) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardGroupPayload(Group $group, ?bool $canManageActivities = null): array
    {
        $currentUserId = auth()->id();
        $canModerateGroup = $group->hasModeratorAccess($currentUserId);
        $isMember = $group->hasMember($currentUserId);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'datacenter' => $group->datacenter,
            'current_user_role' => $group->memberships
                ->firstWhere('user_id', $currentUserId)
                ?->role,
            'features' => $group->featureSettings(),
            'permissions' => [
                'can_manage_group' => $group->isOwnedBy($currentUserId),
                'can_manage_members' => $canModerateGroup,
                'can_manage_discovery' => $group->hasAdminAccess($currentUserId),
                'can_manage_activities' => $canManageActivities ?? $canModerateGroup,
                'can_view_members' => $isMember,
                'can_review_membership_applications' => $group->usesMembershipApplications() && $canModerateGroup,
                'can_manage_membership_application_form' => $group->usesMembershipApplications() && $group->hasAdminAccess($currentUserId),
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
                GroupMembership::ROLE_ADMIN,
                GroupMembership::ROLE_MODERATOR,
            ], true))
            ->pluck('user_id')
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
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
     * @return Collection<int, array<string, mixed>>
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
            'small_image_url' => $activityType->currentPublishedVersion?->small_image_url,
            'banner_image_url' => $activityType->currentPublishedVersion?->banner_image_url,
            'difficulty' => $activityType->currentPublishedVersion?->difficulty,
            'default_min_item_level' => $activityType->currentPublishedVersion?->default_min_item_level,
            'slot_count' => collect($activityType->currentPublishedVersion?->layout_schema['groups'] ?? [])
                ->sum(fn (array $groupDefinition) => (int) ($groupDefinition['size'] ?? 0)),
            'prog_points' => $includeProgPoints
                ? ($activityType->currentPublishedVersion?->prog_points ?? [])
                : [],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function activityOptionsForForm(): array
    {
        return [
            'intensities' => Activity::INTENSITIES,
            'runStyles' => Activity::RUN_STYLES,
        ];
    }
}
