<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithGroupActivityAttendees;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityApplicationAnswer;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\PhantomJob;
use App\Models\RaidPosition;
use App\Models\UserActivityApplicationDefault;
use App\Services\Groups\ActivityApplicationCharacterRefreshService;
use App\Services\Groups\ActivityApplicationWithdrawalService;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Lodestone\LodestoneCharacterSearchService;
use App\Services\Notifications\ApplicationNotificationService;
use App\Support\Input\RequestTextInputSanitizer;
use App\Support\Input\TextInputSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GroupActivityApplicationController extends Controller
{
    use InteractsWithGroupActivityAttendees;

    private const ANY_OPTION_KEY = 'any';

    private const APPLICATION_CHARACTER_REFRESH_COOLDOWN_SECONDS = 3600;

    public function __construct(
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly LodestoneCharacterSearchService $lodestoneCharacterSearchService,
        private readonly ApplicationNotificationService $applicationNotificationService,
        private readonly RequestTextInputSanitizer $requestTextInputSanitizer,
        private readonly TextInputSanitizer $textInputSanitizer,
        private readonly ActivityApplicationWithdrawalService $applicationWithdrawalService,
        private readonly ActivityApplicationCharacterRefreshService $applicationCharacterRefreshService,
    ) {}

    public function show(Request $request, Group $group, Activity $activity, ?string $secretKey = null): Response
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey, allowParticipationBlocked: true);

        $activity->load(array_merge($this->attendeeActivityRelations(), [
            'applications.answers',
        ]));
        $activity->loadCount([
            'slots',
            'applications as pending_application_count' => fn ($query) => $query->where('status', ActivityApplication::STATUS_PENDING),
        ]);
        $activity->setAttribute('assigned_slot_count', $activity->slots()->whereNotNull('assigned_character_id')->count());

        $existingApplication = $request->user()
            ? $activity->applications
                ->where('user_id', $request->user()->id)
                ->first(fn (ActivityApplication $application) => $application->status !== ActivityApplication::STATUS_WITHDRAWN)
            : null;

        return $this->renderApplicationPage(
            request: $request,
            group: $group,
            activity: $activity,
            application: $existingApplication,
            secretKey: $secretKey,
        );
    }

    public function editGuest(Request $request, Group $group, Activity $activity, string $accessToken, ?string $secretKey = null): Response|RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey, allowArchivedGuestAccess: true);

        $activity->load(array_merge($this->attendeeActivityRelations(), [
            'applications.answers',
        ]));
        $activity->loadCount([
            'slots',
            'applications as pending_application_count' => fn ($query) => $query->where('status', ActivityApplication::STATUS_PENDING),
        ]);
        $activity->setAttribute('assigned_slot_count', $activity->slots()->whereNotNull('assigned_character_id')->count());

        $application = $this->findGuestApplicationByAccessToken($activity, $accessToken);

        if (! $this->applicationIsEditable($activity, $application)) {
            return redirect()->route('groups.activities.application.status', [
                ...$this->activityAttendeeRouteParameters($group, $activity, $secretKey),
                'accessToken' => $accessToken,
            ]);
        }

        return $this->renderApplicationPage(
            request: $request,
            group: $group,
            activity: $activity,
            application: $application,
            secretKey: $secretKey,
            guestAccessToken: $accessToken,
        );
    }

    public function confirmation(Request $request, Group $group, Activity $activity, ?string $secretKey = null): Response|RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey);

        $confirmation = $request->session()->get($this->confirmationSessionKey($activity->id));

        if (! is_array($confirmation) || blank($confirmation['application_id'] ?? null)) {
            return redirect()->route('groups.activities.application', $this->activityAttendeeRouteParameters($group, $activity, $secretKey));
        }

        $activity->load($this->attendeeActivityRelations());
        $activity->loadCount([
            'slots',
            'applications as pending_application_count' => fn ($query) => $query->where('status', ActivityApplication::STATUS_PENDING),
        ]);
        $activity->setAttribute('assigned_slot_count', $activity->slots()->whereNotNull('assigned_character_id')->count());

        $application = $activity->applications()
            ->with('answers')
            ->find($confirmation['application_id']);

        if (! $application instanceof ActivityApplication) {
            return redirect()->route('groups.activities.application', $this->activityAttendeeRouteParameters($group, $activity, $secretKey));
        }

        if ($application->user_id !== null && $application->user_id !== $request->user()?->id) {
            abort(403);
        }

        return Inertia::render('Groups/Activities/ApplicationConfirmation', [
            'group' => $this->serializePublicGroup($group),
            'activity' => $this->serializeAttendeeActivity($activity),
            'applicationSchema' => $this->serializeApplicationSchema($activity->activityTypeVersion),
            'application' => $this->serializeExistingApplication($application),
            'secretKey' => null,
            'guestAccessToken' => null,
            'confirmation' => [
                'view' => 'confirmation',
                'mode' => ($confirmation['mode'] ?? 'submitted') === 'updated' ? 'updated' : 'submitted',
                'can_edit' => $application->user_id !== null
                    && $application->user_id === $request->user()?->id
                    && $this->applicationIsEditable($activity, $application),
                'can_withdraw' => $application->user_id !== null
                    && $application->user_id === $request->user()?->id
                    && $this->applicationWithdrawalService->applicationCanBeWithdrawn($activity, $application),
            ],
        ]);
    }

    public function status(Request $request, Group $group, Activity $activity, string $accessToken, ?string $secretKey = null): Response
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey, allowArchivedGuestAccess: true);

        $activity->load($this->attendeeActivityRelations());
        $activity->loadCount([
            'slots',
            'applications as pending_application_count' => fn ($query) => $query->where('status', ActivityApplication::STATUS_PENDING),
        ]);
        $activity->setAttribute('assigned_slot_count', $activity->slots()->whereNotNull('assigned_character_id')->count());

        $application = $activity->applications()
            ->with('answers')
            ->whereNull('user_id')
            ->where('guest_access_token', $accessToken)
            ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)
            ->firstOrFail();

        return Inertia::render('Groups/Activities/ApplicationConfirmation', [
            'group' => $this->serializePublicGroup($group),
            'activity' => $this->serializeAttendeeActivity($activity),
            'applicationSchema' => $this->serializeApplicationSchema($activity->activityTypeVersion),
            'application' => $this->serializeExistingApplication($application),
            'secretKey' => null,
            'guestAccessToken' => $accessToken,
            'confirmation' => [
                'view' => 'status',
                'mode' => 'submitted',
                'can_edit' => $this->applicationIsEditable($activity, $application),
                'can_withdraw' => $this->applicationWithdrawalService->applicationCanBeWithdrawn($activity, $application),
            ],
        ]);
    }

    public function searchCharacters(Request $request, Group $group, Activity $activity, ?string $secretKey = null): JsonResponse
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey);

        if (! $activity->allow_guest_applications) {
            abort(404);
        }

        $this->requestTextInputSanitizer->sanitize($request, [
            'name',
            'world',
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'world' => [
                'required',
                'string',
                Rule::in($this->lodestoneCharacterSearchService->availableWorlds()),
            ],
        ]);

        $results = $this->lodestoneCharacterSearchService->search(
            (string) $validated['name'],
            (string) $validated['world'],
        );

        $verifiedLodestoneIds = Character::query()
            ->whereIn('lodestone_id', collect($results)->map(fn ($result) => $result->lodestoneId)->all())
            ->whereNotNull('verified_at')
            ->pluck('lodestone_id')
            ->all();

        $results = array_values(array_filter(
            $results,
            fn ($result): bool => ! in_array($result->lodestoneId, $verifiedLodestoneIds, true),
        ));

        return response()->json([
            'data' => array_map(
                fn ($result) => $result->toArray(),
                $results,
            ),
        ]);
    }

    public function store(Request $request, Group $group, Activity $activity, ?string $secretKey = null): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey);

        $user = $request->user();
        $activity->loadMissing('activityTypeVersion');
        $this->ensureActivityAcceptsApplications($activity);

        if ($user && $activity->applications()
            ->where('user_id', $user->id)
            ->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN)
            ->exists()) {
            abort(422, 'You have already submitted an application for this activity.');
        }

        if (! $user && ! $activity->allow_guest_applications) {
            abort(403);
        }

        $validated = $this->validateApplicationPayload($request, $activity, $user?->id);

        if ($this->hasExistingApplicationForApplicantLodestoneId($activity, $validated['applicant']['lodestone_id'])) {
            abort(422, 'An application already exists for this character.');
        }

        if ($user && isset($validated['selected_character_id'])) {
            $this->ensureSelectedCharacterIsNotAlreadyAssigned($activity, (int) $validated['selected_character_id']);
        }

        $application = DB::transaction(function () use ($activity, $user, $validated) {
            $selectedCharacterId = $user
                ? ($validated['selected_character_id'] ?? null)
                : $this->resolveGuestApplicantCharacter($validated['applicant'])->id;

            $application = $activity->applications()->create([
                'user_id' => $user?->id,
                'selected_character_id' => $selectedCharacterId,
                'applicant_lodestone_id' => $validated['applicant']['lodestone_id'],
                'applicant_character_name' => $validated['applicant']['name'],
                'applicant_world' => $validated['applicant']['world'],
                'applicant_datacenter' => $validated['applicant']['datacenter'],
                'applicant_avatar_url' => $validated['applicant']['avatar_url'],
                'guest_access_token' => $user ? null : ActivityApplication::generateGuestAccessToken(),
                'status' => ActivityApplication::STATUS_PENDING,
                'notes' => $validated['notes'] ?? null,
                'reviewed_by_user_id' => null,
                'submitted_at' => now(),
                'reviewed_at' => null,
                'review_reason' => null,
            ]);

            $this->syncApplicationAnswers($application, $validated['answers'] ?? []);

            if ($user && (bool) ($validated['remember_application_defaults'] ?? false)) {
                $this->storeRememberedApplicationDefaults(
                    userId: $user->id,
                    activity: $activity,
                    selectedCharacterId: $validated['selected_character_id'] ?? null,
                    answers: $validated['answers'] ?? [],
                    notes: $validated['notes'] ?? null,
                );
            }

            $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);
            $this->activityAuditService->logApplicationSubmitted($application, $user);

            return $application;
        });

        if ($user) {
            $this->refreshApplicationCharacterForApplicant($application);
        }

        $this->applicationNotificationService->notifySubmitted(
            $application->fresh(['activity.group', 'selectedCharacter', 'user']),
            $user,
        );

        if (! $user) {
            return redirect()->route('groups.activities.application.status', [
                ...$this->activityAttendeeRouteParameters($group, $activity, $secretKey),
                'accessToken' => $application->guest_access_token,
            ]);
        }

        $request->session()->put($this->confirmationSessionKey($activity->id), [
            'application_id' => $application->id,
            'mode' => 'submitted',
        ]);

        return redirect()
            ->route('groups.activities.application.confirmation', $this->activityAttendeeRouteParameters($group, $activity, $secretKey));
    }

    public function update(Request $request, Group $group, Activity $activity, ?string $secretKey = null): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey);

        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $activity->loadMissing('activityTypeVersion');
        $this->ensureActivityAcceptsApplications($activity);

        /** @var ActivityApplication|null $application */
        $application = $activity->applications()
            ->with('answers')
            ->where('user_id', $user->id)
            ->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN)
            ->first();

        if (! $application) {
            abort(404);
        }

        if (! $this->applicationIsEditable($activity, $application)) {
            abort(403);
        }

        $validated = $this->validateApplicationPayload($request, $activity, $user->id);

        if ($this->hasExistingApplicationForApplicantLodestoneId(
            $activity,
            $validated['applicant']['lodestone_id'],
            $application->id,
        )) {
            abort(422, 'An application already exists for this character.');
        }

        if (isset($validated['selected_character_id'])) {
            $this->ensureSelectedCharacterIsNotAlreadyAssigned($activity, (int) $validated['selected_character_id']);
        }

        $applicationId = DB::transaction(function () use ($application, $validated) {
            $application->update([
                'selected_character_id' => $validated['selected_character_id'] ?? null,
                'applicant_lodestone_id' => $validated['applicant']['lodestone_id'],
                'applicant_character_name' => $validated['applicant']['name'],
                'applicant_world' => $validated['applicant']['world'],
                'applicant_datacenter' => $validated['applicant']['datacenter'],
                'applicant_avatar_url' => $validated['applicant']['avatar_url'],
                'status' => ActivityApplication::STATUS_PENDING,
                'notes' => $validated['notes'] ?? null,
                'reviewed_by_user_id' => null,
                'submitted_at' => now(),
                'reviewed_at' => null,
                'review_reason' => null,
            ]);

            $this->syncApplicationAnswers($application, $validated['answers'] ?? []);
            $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);
            $this->activityAuditService->logApplicationUpdated($application, auth()->user());

            return $application->id;
        });

        $this->refreshApplicationCharacterForApplicant($application->fresh('selectedCharacter'));

        $this->applicationNotificationService->notifyUpdated(
            $application->fresh(['activity.group', 'selectedCharacter', 'user']),
            $user,
        );

        $request->session()->put($this->confirmationSessionKey($activity->id), [
            'application_id' => $applicationId,
            'mode' => 'updated',
        ]);

        return redirect()
            ->route('groups.activities.application.confirmation', $this->activityAttendeeRouteParameters($group, $activity, $secretKey));
    }

    private function refreshApplicationCharacterForApplicant(ActivityApplication $application): void
    {
        try {
            $this->applicationCharacterRefreshService->refreshSelectedCharacterIfDue(
                $application,
                self::APPLICATION_CHARACTER_REFRESH_COOLDOWN_SECONDS,
            );
        } catch (\Throwable $exception) {
            Log::warning('Unable to refresh Lodestone data during activity application submission.', [
                'activity_application_id' => $application->id,
                'selected_character_id' => $application->selected_character_id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function updateGuest(Request $request, Group $group, Activity $activity, string $accessToken, ?string $secretKey = null): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey, allowArchivedGuestAccess: true);

        $activity->loadMissing('activityTypeVersion');
        $this->ensureActivityAcceptsApplications($activity);
        $application = $this->findGuestApplicationByAccessToken($activity, $accessToken);

        if (! $this->applicationIsEditable($activity, $application)) {
            abort(403);
        }

        $validated = $this->validateApplicationPayload($request, $activity);

        if ($this->hasExistingApplicationForApplicantLodestoneId(
            $activity,
            $validated['applicant']['lodestone_id'],
            $application->id,
        )) {
            abort(422, 'An application already exists for this character.');
        }

        DB::transaction(function () use ($application, $validated) {
            $selectedCharacter = $this->resolveGuestApplicantCharacter($validated['applicant']);

            $application->update([
                'selected_character_id' => $selectedCharacter->id,
                'applicant_lodestone_id' => $validated['applicant']['lodestone_id'],
                'applicant_character_name' => $validated['applicant']['name'],
                'applicant_world' => $validated['applicant']['world'],
                'applicant_datacenter' => $validated['applicant']['datacenter'],
                'applicant_avatar_url' => $validated['applicant']['avatar_url'],
                'status' => ActivityApplication::STATUS_PENDING,
                'notes' => $validated['notes'] ?? null,
                'reviewed_by_user_id' => null,
                'submitted_at' => now(),
                'reviewed_at' => null,
                'review_reason' => null,
            ]);

            $this->syncApplicationAnswers($application, $validated['answers'] ?? []);
            $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);
            $this->activityAuditService->logApplicationUpdated($application, null);
        });

        $this->applicationNotificationService->notifyUpdated(
            $application->fresh(['activity.group', 'selectedCharacter', 'user']),
            null,
        );

        return redirect()->route('groups.activities.application.status', [
            ...$this->activityAttendeeRouteParameters($group, $activity, $secretKey),
            'accessToken' => $application->guest_access_token,
        ]);
    }

    public function destroyGuest(Request $request, Group $group, Activity $activity, string $accessToken, ?string $secretKey = null): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey, allowArchivedGuestAccess: true);

        $application = $this->findGuestApplicationByAccessToken($activity, $accessToken);
        $this->applicationWithdrawalService->withdraw($application, null);

        return redirect()
            ->route('groups.activities.application', $this->activityAttendeeRouteParameters($group, $activity, $secretKey))
            ->with('success', 'application_withdrawn');
    }

    private function ensureApplicationPageAccessible(
        Request $request,
        Group $group,
        Activity $activity,
        ?string $secretKey,
        bool $allowArchivedGuestAccess = false,
        bool $allowParticipationBlocked = false,
    ): void {
        $this->ensureActivityBelongsToGroup($group, $activity);

        if (! $this->canAccessOverview($request, $group, $activity, $secretKey)) {
            abort(404);
        }

        if (! $activity->needs_application) {
            abort(404);
        }

        if (
            ! $allowParticipationBlocked
            && ! $this->canUseActivityParticipationFlow($group, $activity, $request->user()?->id)
        ) {
            abort(404);
        }

        if (
            $activity->isArchived()
            && ! ($allowArchivedGuestAccess && $activity->status === Activity::STATUS_CANCELLED)
        ) {
            abort(404);
        }
    }

    private function renderApplicationPage(
        Request $request,
        Group $group,
        Activity $activity,
        ?ActivityApplication $application,
        ?string $secretKey = null,
        ?string $guestAccessToken = null,
    ): Response {
        $acceptsApplications = $activity->acceptsApplications();
        $canUseParticipationFlow = $this->canUseActivityParticipationFlow(
            $group,
            $activity,
            $request->user()?->id,
        );
        $requiresGroupMembership = $acceptsApplications
            && $guestAccessToken === null
            && $application === null
            && $request->user() !== null
            && ! $canUseParticipationFlow;

        return Inertia::render('Groups/Activities/Application', [
            'group' => $this->serializePublicGroup($group),
            'activity' => $this->serializeAttendeeActivity($activity),
            'applicationSchema' => $this->serializeApplicationSchema($activity->activityTypeVersion),
            'application' => $this->serializeExistingApplication($application),
            'rememberedApplicationDefaults' => $this->serializeRememberedApplicationDefaults(
                userId: $request->user()?->id,
                activity: $activity,
                activityTypeVersion: $activity->activityTypeVersion,
                existingApplication: $application,
                guestAccessToken: $guestAccessToken,
            ),
            'characters' => $request->user()
                ? $this->applicationCharactersForUser($request->user()->id)
                : [],
            'guestCharacterSearch' => [
                'worlds' => $this->lodestoneCharacterSearchService->worldOptions(),
            ],
            'secretKey' => null,
            'guestAccessToken' => $guestAccessToken,
            'permissions' => [
                'can_apply' => $acceptsApplications
                    && $guestAccessToken === null
                    && $request->user() !== null
                    && $canUseParticipationFlow,
                'can_apply_as_guest' => $acceptsApplications
                    && $activity->is_public
                    && $canUseParticipationFlow
                    && (($request->user() === null && $activity->allow_guest_applications) || $guestAccessToken !== null),
                'can_edit_application' => $application ? $this->applicationIsEditable($activity, $application) : $acceptsApplications,
                'can_withdraw_application' => $application ? $this->applicationWithdrawalService->applicationCanBeWithdrawn($activity, $application) : false,
                'can_manage' => $group->hasModeratorAccess($request->user()?->id),
                'has_existing_application' => $application !== null,
                'requires_group_membership' => $requiresGroupMembership,
                'can_join_group' => $requiresGroupMembership && $group->allowsOpenJoin(),
                'can_request_group_membership' => $requiresGroupMembership
                    && $group->usesMembershipApplications()
                    && $group->is_visible,
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeApplicationSchema(?ActivityTypeVersion $activityTypeVersion): array
    {
        return collect($activityTypeVersion?->application_schema ?? [])
            ->map(fn (array $question) => [
                'key' => (string) ($question['key'] ?? ''),
                'label' => is_array($question['label'] ?? null) ? $question['label'] : ['en' => (string) ($question['key'] ?? '')],
                'type' => (string) ($question['type'] ?? 'text'),
                'source' => $question['source'] ?? null,
                'required' => (bool) ($question['required'] ?? false),
                'help_text' => is_array($question['help_text'] ?? null) ? $question['help_text'] : null,
                'accepts_any' => (bool) ($question['accepts_any'] ?? false),
                'any_label' => is_array($question['any_label'] ?? null) ? $question['any_label'] : null,
                'options' => $this->resolveQuestionOptions($question),
            ])
            ->filter(fn (array $question) => $question['key'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<int, array<string, mixed>>
     */
    private function resolveQuestionOptions(array $question): array
    {
        $options = match ($question['source'] ?? null) {
            'character_classes' => CharacterClass::query()
                ->orderBy('name')
                ->get()
                ->map(fn (CharacterClass $characterClass) => [
                    'key' => (string) $characterClass->id,
                    'label' => ['en' => $characterClass->name],
                    'meta' => [
                        'icon_url' => $characterClass->icon_url,
                        'role' => $characterClass->role,
                        'shorthand' => $characterClass->shorthand,
                    ],
                ])
                ->values()
                ->all(),
            'phantom_jobs' => PhantomJob::query()
                ->orderBy('name')
                ->get()
                ->map(fn (PhantomJob $phantomJob) => [
                    'key' => (string) $phantomJob->id,
                    'label' => ['en' => $phantomJob->name],
                    'meta' => [
                        'icon_url' => $phantomJob->icon_url,
                        'transparent_icon_url' => $phantomJob->transparent_icon_url,
                        'sprite_url' => $phantomJob->sprite_url,
                    ],
                ])
                ->values()
                ->all(),
            'raid_positions' => RaidPosition::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (RaidPosition $raidPosition) => [
                    'key' => $raidPosition->key,
                    'label' => ['en' => $raidPosition->name],
                    'meta' => [
                        'icon_url' => $raidPosition->icon_url,
                    ],
                ])
                ->values()
                ->all(),
            default => collect($question['options'] ?? [])
                ->map(fn (array $option) => [
                    'key' => (string) ($option['key'] ?? $option['value'] ?? ''),
                    'label' => is_array($option['label'] ?? null)
                        ? $option['label']
                        : ['en' => (string) ($option['key'] ?? $option['value'] ?? '')],
                    'meta' => is_array($option['meta'] ?? null) ? $option['meta'] : null,
                ])
                ->filter(fn (array $option) => $option['key'] !== '')
                ->values()
                ->all(),
        };

        return $this->appendAnyOption($question, $options);
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<int, array<string, mixed>>  $options
     * @return array<int, array<string, mixed>>
     */
    private function appendAnyOption(array $question, array $options): array
    {
        if (! $this->supportsAnyOption($question)) {
            return $options;
        }

        $hasAnyOption = collect($options)
            ->contains(fn (array $option) => (string) ($option['key'] ?? '') === self::ANY_OPTION_KEY);

        if ($hasAnyOption) {
            return $options;
        }

        $options[] = [
            'key' => self::ANY_OPTION_KEY,
            'label' => $this->anyOptionLabel($question),
            'meta' => [
                'is_any' => true,
            ],
        ];

        return $options;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, string|null>
     */
    private function anyOptionLabel(array $question): array
    {
        return is_array($question['any_label'] ?? null)
            ? $question['any_label']
            : ['en' => 'Any'];
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function supportsAnyOption(array $question): bool
    {
        return (bool) ($question['accepts_any'] ?? false)
            && in_array((string) ($question['type'] ?? ''), ['single_select', 'multi_select'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeExistingApplication(?ActivityApplication $application): ?array
    {
        if (! $application) {
            return null;
        }

        return [
            'id' => $application->id,
            'selected_character_id' => $application->selected_character_id,
            'status' => $application->status,
            'is_rostered' => $this->applicationWithdrawalService->applicationIsRostered($application),
            'notes' => $application->notes,
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'review_reason' => $application->review_reason,
            'applicant_character' => $application->applicant_lodestone_id ? [
                'lodestone_id' => $application->applicant_lodestone_id,
                'name' => $application->applicant_character_name,
                'world' => $application->applicant_world,
                'datacenter' => $application->applicant_datacenter,
                'avatar_url' => $application->applicant_avatar_url,
            ] : null,
            'answers' => $application->answers
                ->mapWithKeys(fn ($answer) => [$answer->question_key => $answer->value])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeRememberedApplicationDefaults(
        ?int $userId,
        Activity $activity,
        ?ActivityTypeVersion $activityTypeVersion,
        ?ActivityApplication $existingApplication,
        ?string $guestAccessToken = null,
    ): ?array {
        if ($userId === null || $existingApplication !== null || $guestAccessToken !== null) {
            return null;
        }

        $defaults = UserActivityApplicationDefault::query()
            ->where('user_id', $userId)
            ->where('activity_type_id', $activity->activity_type_id)
            ->first();

        if (! $defaults instanceof UserActivityApplicationDefault) {
            return null;
        }

        $characterIds = Character::query()
            ->where('user_id', $userId)
            ->whereNotNull('verified_at')
            ->pluck('id')
            ->all();

        $selectedCharacterId = in_array($defaults->selected_character_id, $characterIds, true)
            ? $defaults->selected_character_id
            : null;

        $notes = is_string($defaults->notes) && mb_strlen($defaults->notes) <= ActivityApplication::NOTES_MAX_LENGTH
            ? $defaults->notes
            : null;

        $answers = $this->filterRememberedApplicationAnswers(
            $defaults->answers ?? [],
            $activityTypeVersion,
        );

        if ($selectedCharacterId === null && $notes === null && $answers === []) {
            return null;
        }

        return [
            'selected_character_id' => $selectedCharacterId,
            'notes' => $notes,
            'answers' => $answers,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function applicationCharactersForUser(int $userId): array
    {
        return Character::query()
            ->with([
                'classes:id',
                'phantomJobs:id',
            ])
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get()
            ->map(fn (Character $character) => [
                'id' => $character->id,
                'name' => $character->name,
                'avatar_url' => $character->avatar_url,
                'world' => $character->world,
                'preferred_character_class_ids' => $character->classes
                    ->filter(fn (CharacterClass $characterClass) => (bool) $characterClass->pivot?->is_preferred)
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->values()
                    ->all(),
                'preferred_phantom_job_ids' => $character->phantomJobs
                    ->filter(fn (PhantomJob $phantomJob) => (bool) $phantomJob->pivot?->is_preferred)
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $answers
     */
    private function storeRememberedApplicationDefaults(
        int $userId,
        Activity $activity,
        ?int $selectedCharacterId,
        array $answers,
        ?string $notes,
    ): void {
        $defaults = UserActivityApplicationDefault::query()
            ->where('user_id', $userId)
            ->where('activity_type_id', $activity->activity_type_id)
            ->first();

        if (! $defaults instanceof UserActivityApplicationDefault) {
            $defaults = new UserActivityApplicationDefault;
            $defaults->forceFill([
                'user_id' => $userId,
                'activity_type_id' => $activity->activity_type_id,
            ]);
        }

        $defaults->forceFill([
            'selected_character_id' => $selectedCharacterId,
            'answers' => collect($answers)
                ->mapWithKeys(fn (array $answer) => [
                    (string) ($answer['question_key'] ?? '') => $answer['value'] ?? null,
                ])
                ->filter(fn ($value, string $key) => $key !== '')
                ->all(),
            'notes' => $notes,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateApplicationPayload(Request $request, Activity $activity, ?int $userId = null): array
    {
        $this->requestTextInputSanitizer->sanitize(
            $request,
            ['guest_applicant.name', 'guest_applicant.world', 'guest_applicant.datacenter'],
            ['notes'],
        );

        $validated = $request->validate([
            'selected_character_id' => [$userId ? 'sometimes' : 'prohibited', 'nullable', 'integer', 'exists:characters,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.ActivityApplication::NOTES_MAX_LENGTH],
            'answers' => ['sometimes', 'array'],
            'remember_application_defaults' => [$userId ? 'sometimes' : 'prohibited', 'boolean'],
            'guest_applicant' => [$userId ? 'prohibited' : 'required', 'array'],
            'guest_applicant.lodestone_id' => [$userId ? 'prohibited' : 'required', 'string', 'max:255'],
            'guest_applicant.name' => [$userId ? 'prohibited' : 'required', 'string', 'max:255'],
            'guest_applicant.world' => [$userId ? 'prohibited' : 'required', 'string', 'max:255'],
            'guest_applicant.datacenter' => [$userId ? 'prohibited' : 'required', 'string', 'max:255'],
            'guest_applicant.avatar_url' => [$userId ? 'prohibited' : 'nullable', 'url', 'max:2048'],
        ]);

        $characterId = $validated['selected_character_id'] ?? null;
        $selectedCharacter = null;

        if ($characterId) {
            $selectedCharacter = Character::query()->find($characterId);

            if (! $selectedCharacter || $selectedCharacter->user_id !== $userId) {
                throw ValidationException::withMessages([
                    'selected_character_id' => 'The selected character is invalid for this application.',
                ]);
            }
        }

        $validated['applicant'] = $selectedCharacter
            ? $this->characterApplicantSnapshot($selectedCharacter)
            : $this->guestApplicantSnapshot($validated['guest_applicant'] ?? []);

        if (! $userId) {
            $this->ensureGuestApplicantCanBeUsed($validated['applicant']);
        }

        $validated['answers'] = $this->normalizeApplicationAnswers(
            $validated['answers'] ?? [],
            $activity->activityTypeVersion
        );
        $validated['answers'] = $this->sanitizeApplicationAnswers($validated['answers']);
        $this->validateApplicationAnswerLengths($validated['answers']);

        $requiredQuestionKeys = collect($activity->activityTypeVersion?->application_schema ?? [])
            ->filter(fn ($question) => is_array($question) && filled($question['key'] ?? null) && (bool) ($question['required'] ?? false))
            ->pluck('key')
            ->map(fn ($key) => (string) $key);

        $answersByKey = collect($validated['answers'])->keyBy('question_key');

        foreach ($requiredQuestionKeys as $questionKey) {
            $answer = $answersByKey->get($questionKey);
            $value = $answer['value'] ?? null;

            $isEmpty = match (true) {
                is_array($value) => count(array_filter($value, fn ($entry) => ! blank($entry))) === 0,
                is_bool($value) => false,
                default => blank($value),
            };

            if ($isEmpty) {
                throw ValidationException::withMessages([
                    sprintf('answers.%s', $questionKey) => sprintf('The %s field is required.', $questionKey),
                ]);
            }
        }

        return $validated;
    }

    private function hasExistingApplicationForApplicantLodestoneId(Activity $activity, string $lodestoneId, ?int $ignoreApplicationId = null): bool
    {
        $query = $activity->applications()
            ->where('applicant_lodestone_id', $lodestoneId)
            ->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN);

        if ($ignoreApplicationId !== null) {
            $query->whereKeyNot($ignoreApplicationId);
        }

        return $query->exists();
    }

    /**
     * @param  array{lodestone_id: string, name: string, world: string, datacenter: string, avatar_url: ?string}  $applicant
     */
    private function ensureGuestApplicantCanBeUsed(array $applicant): void
    {
        $verifiedCharacterExists = Character::query()
            ->where('lodestone_id', $applicant['lodestone_id'])
            ->whereNotNull('verified_at')
            ->exists();

        if ($verifiedCharacterExists) {
            throw ValidationException::withMessages([
                'guest_applicant.lodestone_id' => 'This character is already claimed by a verified FullParty account.',
            ]);
        }
    }

    /**
     * @param  array{lodestone_id: string, name: string, world: string, datacenter: string, avatar_url: ?string}  $applicant
     */
    private function resolveGuestApplicantCharacter(array $applicant): Character
    {
        $character = Character::query()
            ->where('lodestone_id', $applicant['lodestone_id'])
            ->lockForUpdate()
            ->first();

        if (! $character) {
            return Character::query()->create([
                'user_id' => null,
                'is_primary' => false,
                'name' => $applicant['name'],
                'world' => $applicant['world'],
                'datacenter' => $applicant['datacenter'],
                'lodestone_id' => $applicant['lodestone_id'],
                'avatar_url' => $applicant['avatar_url'],
                'token' => null,
                'expires_at' => null,
                'verified_at' => null,
                'add_method' => 'guest_application',
            ]);
        }

        if ($character->verified_at !== null) {
            throw ValidationException::withMessages([
                'guest_applicant.lodestone_id' => 'This character is already claimed by a verified FullParty account.',
            ]);
        }

        $character->fill([
            'name' => $applicant['name'],
            'world' => $applicant['world'],
            'datacenter' => $applicant['datacenter'],
            'avatar_url' => $applicant['avatar_url'],
        ]);

        if ($character->isDirty(['name', 'world', 'datacenter', 'avatar_url'])) {
            $character->save();
        }

        return $character;
    }

    private function findGuestApplicationByAccessToken(Activity $activity, string $accessToken): ActivityApplication
    {
        return $activity->applications()
            ->with('answers')
            ->whereNull('user_id')
            ->where('guest_access_token', $accessToken)
            ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)
            ->firstOrFail();
    }

    private function confirmationSessionKey(int $activityId): string
    {
        return sprintf('activities.%d.application_confirmation', $activityId);
    }

    private function applicationIsEditable(Activity $activity, ActivityApplication $application): bool
    {
        return $activity->acceptsApplications()
            && in_array($application->status, ActivityApplication::ACTIVE_STATUSES, true)
            && ! $this->applicationIsAssignedToRoster($activity, $application);
    }

    private function applicationIsAssignedToRoster(Activity $activity, ActivityApplication $application): bool
    {
        if (! $application->selected_character_id) {
            return false;
        }

        return $activity->slots()
            ->where('assigned_character_id', $application->selected_character_id)
            ->where('group_key', '!=', ActivitySlotBench::GROUP_KEY)
            ->exists();
    }

    private function ensureSelectedCharacterIsNotAlreadyAssigned(Activity $activity, int $characterId): void
    {
        $assignedSlotExists = $activity->slots()
            ->where('assigned_character_id', $characterId)
            ->where('group_key', '!=', ActivitySlotBench::GROUP_KEY)
            ->exists();

        if (! $assignedSlotExists) {
            return;
        }

        throw ValidationException::withMessages([
            'selected_character_id' => 'This character is already assigned to this run.',
        ]);
    }

    private function ensureActivityAcceptsApplications(Activity $activity): void
    {
        if (! $activity->acceptsApplications()) {
            abort(403);
        }
    }

    /**
     * @return array{lodestone_id: string, name: string, world: string, datacenter: string, avatar_url: ?string}
     */
    private function characterApplicantSnapshot(Character $character): array
    {
        return [
            'lodestone_id' => (string) $character->lodestone_id,
            'name' => (string) $character->name,
            'world' => (string) $character->world,
            'datacenter' => (string) $character->datacenter,
            'avatar_url' => filled($character->avatar_url) ? (string) $character->avatar_url : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $guestApplicant
     * @return array{lodestone_id: string, name: string, world: string, datacenter: string, avatar_url: ?string}
     */
    private function guestApplicantSnapshot(array $guestApplicant): array
    {
        return [
            'lodestone_id' => trim((string) ($guestApplicant['lodestone_id'] ?? '')),
            'name' => trim((string) ($guestApplicant['name'] ?? '')),
            'world' => trim((string) ($guestApplicant['world'] ?? '')),
            'datacenter' => trim((string) ($guestApplicant['datacenter'] ?? '')),
            'avatar_url' => filled($guestApplicant['avatar_url'] ?? null)
                ? trim((string) $guestApplicant['avatar_url'])
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function filterRememberedApplicationAnswers(array $answers, ?ActivityTypeVersion $activityTypeVersion): array
    {
        $questionDefinitions = collect($activityTypeVersion?->application_schema ?? [])
            ->filter(fn ($question) => is_array($question) && filled($question['key'] ?? null))
            ->mapWithKeys(fn (array $question) => [(string) $question['key'] => $question]);

        $filtered = [];

        foreach ($questionDefinitions as $questionKey => $question) {
            if (! array_key_exists($questionKey, $answers)) {
                continue;
            }

            $value = $this->filterRememberedApplicationAnswerValue(
                value: $answers[$questionKey],
                question: $question,
            );

            if ($value === null) {
                continue;
            }

            $filtered[$questionKey] = $value;
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<int, array<string, mixed>>
     */
    private function normalizeApplicationAnswers(array $answers, ?ActivityTypeVersion $activityTypeVersion): array
    {
        $questionDefinitions = collect($activityTypeVersion?->application_schema ?? [])
            ->filter(fn ($question) => is_array($question) && filled($question['key'] ?? null))
            ->mapWithKeys(fn (array $question) => [(string) $question['key'] => $question]);

        return collect($answers)
            ->filter(fn ($value, $key) => is_string($key) && $questionDefinitions->has($key))
            ->map(function ($value, string $key) use ($questionDefinitions) {
                /** @var array<string, mixed> $question */
                $question = $questionDefinitions->get($key);

                return [
                    'question_key' => $key,
                    'question_label' => is_array($question['label'] ?? null) ? $question['label'] : ['en' => $key],
                    'question_type' => (string) ($question['type'] ?? 'text'),
                    'source' => $question['source'] ?? null,
                    'value' => $value,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function filterRememberedApplicationAnswerValue(mixed $value, array $question): mixed
    {
        $questionType = (string) ($question['type'] ?? 'text');

        return match ($questionType) {
            'single_select' => $this->filterRememberedSingleSelectAnswerValue($value, $question),
            'multi_select' => $this->filterRememberedMultiSelectAnswerValue($value, $question),
            'boolean' => $this->filterRememberedBooleanAnswerValue($value),
            'number' => $this->filterRememberedNumberAnswerValue($value),
            'textarea', 'text', 'url' => $this->filterRememberedTextAnswerValue($value, $questionType),
            default => $this->filterRememberedTextAnswerValue($value, 'text'),
        };
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function filterRememberedSingleSelectAnswerValue(mixed $value, array $question): ?string
    {
        if (! is_scalar($value) || blank((string) $value)) {
            return null;
        }

        $normalized = (string) $value;
        $allowedKeys = collect($this->resolveQuestionOptions($question))
            ->pluck('key')
            ->map(fn ($key) => (string) $key)
            ->all();

        return in_array($normalized, $allowedKeys, true) ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<int, string>|null
     */
    private function filterRememberedMultiSelectAnswerValue(mixed $value, array $question): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $allowedKeys = collect($this->resolveQuestionOptions($question))
            ->pluck('key')
            ->map(fn ($key) => (string) $key)
            ->all();

        $filtered = collect($value)
            ->filter(fn ($entry) => is_scalar($entry) && in_array((string) $entry, $allowedKeys, true))
            ->map(fn ($entry) => (string) $entry)
            ->values()
            ->all();

        return $filtered !== [] ? $filtered : null;
    }

    private function filterRememberedBooleanAnswerValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return is_bool($normalized) ? $normalized : null;
        }

        return null;
    }

    private function filterRememberedNumberAnswerValue(mixed $value): ?string
    {
        if (! is_scalar($value) || blank((string) $value) || ! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }

    private function filterRememberedTextAnswerValue(mixed $value, string $questionType): ?string
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        $limit = $this->answerLengthLimit($questionType);

        if ($limit !== null && ! $this->answerValueFitsWithinLimit($value, $limit)) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $answers
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeApplicationAnswers(array $answers): array
    {
        return collect($answers)
            ->map(function (array $answer): array {
                $type = (string) ($answer['question_type'] ?? 'text');

                if ($type === 'textarea') {
                    $answer['value'] = $this->sanitizeAnswerValue($answer['value'] ?? null, multiline: true);

                    return $answer;
                }

                if ($type === 'text') {
                    $answer['value'] = $this->sanitizeAnswerValue($answer['value'] ?? null);
                }

                return $answer;
            })
            ->all();
    }

    private function sanitizeAnswerValue(mixed $value, bool $multiline = false): mixed
    {
        if (is_string($value)) {
            return $multiline
                ? $this->requestTextInputSanitizerValue($value, true)
                : $this->requestTextInputSanitizerValue($value, false);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $entry) {
            $value[$key] = $this->sanitizeAnswerValue($entry, $multiline);
        }

        return $value;
    }

    private function requestTextInputSanitizerValue(string $value, bool $multiline): string
    {
        return $multiline
            ? $this->textInputSanitizer->sanitizeMultiline($value) ?? ''
            : $this->textInputSanitizer->sanitizeSingleLine($value) ?? '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $answers
     */
    private function validateApplicationAnswerLengths(array $answers): void
    {
        foreach ($answers as $answer) {
            $questionKey = (string) ($answer['question_key'] ?? '');
            $limit = $this->answerLengthLimit((string) ($answer['question_type'] ?? ''));

            if ($questionKey === '' || $limit === null) {
                continue;
            }

            if (! $this->answerValueFitsWithinLimit($answer['value'] ?? null, $limit)) {
                throw ValidationException::withMessages([
                    "answers.{$questionKey}" => "The {$questionKey} field must not be greater than {$limit} characters.",
                ]);
            }
        }
    }

    private function answerLengthLimit(string $questionType): ?int
    {
        return match ($questionType) {
            'text' => ActivityApplicationAnswer::TEXT_VALUE_MAX_LENGTH,
            'textarea' => ActivityApplicationAnswer::TEXTAREA_VALUE_MAX_LENGTH,
            'url' => ActivityApplicationAnswer::URL_VALUE_MAX_LENGTH,
            default => null,
        };
    }

    private function answerValueFitsWithinLimit(mixed $value, int $limit): bool
    {
        if (is_string($value)) {
            return mb_strlen($value) <= $limit;
        }

        if (! is_array($value)) {
            return true;
        }

        foreach ($value as $entry) {
            if (! $this->answerValueFitsWithinLimit($entry, $limit)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $answers
     */
    private function syncApplicationAnswers(ActivityApplication $application, array $answers): void
    {
        $application->answers()->delete();

        foreach ($answers as $answer) {
            $application->answers()->create($answer);
        }
    }
}
