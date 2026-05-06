<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithGroupActivityAttendees;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\PhantomJob;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Lodestone\LodestoneCharacterSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GroupActivityApplicationController extends Controller
{
    use InteractsWithGroupActivityAttendees;

    public function __construct(
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly LodestoneCharacterSearchService $lodestoneCharacterSearchService,
    ) {}

    public function show(Request $request, Group $group, Activity $activity, ?string $secretKey = null): Response
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey);

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

        if (!$this->applicationIsEditable($application)) {
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

        if (!is_array($confirmation) || blank($confirmation['application_id'] ?? null)) {
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

        if (!$application instanceof ActivityApplication) {
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
            'secretKey' => $secretKey,
            'guestAccessToken' => null,
            'confirmation' => [
                'view' => 'confirmation',
                'mode' => ($confirmation['mode'] ?? 'submitted') === 'updated' ? 'updated' : 'submitted',
                'can_edit' => $application->user_id !== null
                    && $application->user_id === $request->user()?->id
                    && $this->applicationIsEditable($application),
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
            ->firstOrFail();

        return Inertia::render('Groups/Activities/ApplicationConfirmation', [
            'group' => $this->serializePublicGroup($group),
            'activity' => $this->serializeAttendeeActivity($activity),
            'applicationSchema' => $this->serializeApplicationSchema($activity->activityTypeVersion),
            'application' => $this->serializeExistingApplication($application),
            'secretKey' => $secretKey,
            'guestAccessToken' => $accessToken,
            'confirmation' => [
                'view' => 'status',
                'mode' => 'submitted',
                'can_edit' => $this->applicationIsEditable($application),
            ],
        ]);
    }

    public function searchCharacters(Request $request, Group $group, Activity $activity, ?string $secretKey = null): JsonResponse
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey);

        if (!$activity->allow_guest_applications) {
            abort(404);
        }

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
            fn ($result): bool => !in_array($result->lodestoneId, $verifiedLodestoneIds, true),
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

        if ($user && $activity->applications()
            ->where('user_id', $user->id)
            ->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN)
            ->exists()) {
            abort(422, 'You have already submitted an application for this activity.');
        }

        if (!$user && !$activity->allow_guest_applications) {
            abort(403);
        }

        $validated = $this->validateApplicationPayload($request, $activity, $user?->id);

        if ($this->hasExistingApplicationForApplicantLodestoneId($activity, $validated['applicant']['lodestone_id'])) {
            abort(422, 'An application already exists for this character.');
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
            $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);
            $this->activityAuditService->logApplicationSubmitted($application, $user);

            return $application;
        });

        if (!$user) {
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

        if (!$user) {
            abort(403);
        }

        $activity->loadMissing('activityTypeVersion');

        /** @var ActivityApplication|null $application */
        $application = $activity->applications()
            ->with('answers')
            ->where('user_id', $user->id)
            ->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN)
            ->first();

        if (!$application) {
            abort(404);
        }

        if (!$this->applicationIsEditable($application)) {
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

        $request->session()->put($this->confirmationSessionKey($activity->id), [
            'application_id' => $applicationId,
            'mode' => 'updated',
        ]);

        return redirect()
            ->route('groups.activities.application.confirmation', $this->activityAttendeeRouteParameters($group, $activity, $secretKey));
    }

    public function updateGuest(Request $request, Group $group, Activity $activity, string $accessToken, ?string $secretKey = null): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey, allowArchivedGuestAccess: true);

        $activity->loadMissing('activityTypeVersion');
        $application = $this->findGuestApplicationByAccessToken($activity, $accessToken);

        if (!$this->applicationIsEditable($application)) {
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

        return redirect()->route('groups.activities.application.status', [
            ...$this->activityAttendeeRouteParameters($group, $activity, $secretKey),
            'accessToken' => $application->guest_access_token,
        ]);
    }

    private function ensureApplicationPageAccessible(
        Request $request,
        Group $group,
        Activity $activity,
        ?string $secretKey,
        bool $allowArchivedGuestAccess = false,
    ): void
    {
        $this->ensureActivityBelongsToGroup($group, $activity);

        if (!$this->canAccessOverview($request, $group, $activity, $secretKey)) {
            abort(404);
        }

        // TODO: Add the non-application self-assignment/direct-join flow for activities that do not use applications.
        if (!$activity->needs_application) {
            abort(404);
        }

        if (
            $activity->isArchived()
            && !($allowArchivedGuestAccess && $activity->status === Activity::STATUS_CANCELLED)
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
        return Inertia::render('Groups/Activities/Application', [
            'group' => $this->serializePublicGroup($group),
            'activity' => $this->serializeAttendeeActivity($activity),
            'applicationSchema' => $this->serializeApplicationSchema($activity->activityTypeVersion),
            'application' => $this->serializeExistingApplication($application),
            'characters' => $request->user()
                ? $this->applicationCharactersForUser($request->user()->id)
                : [],
            'guestCharacterSearch' => [
                'worlds' => $this->lodestoneCharacterSearchService->worldOptions(),
            ],
            'secretKey' => $secretKey,
            'guestAccessToken' => $guestAccessToken,
            'permissions' => [
                'can_apply' => $guestAccessToken === null && $request->user() !== null,
                'can_apply_as_guest' => ($request->user() === null && $activity->allow_guest_applications) || $guestAccessToken !== null,
                'can_edit_application' => $application ? $this->applicationIsEditable($application) : true,
                'can_manage' => $group->hasModeratorAccess($request->user()?->id),
                'has_existing_application' => $application !== null,
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
        return match ($question['source'] ?? null) {
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
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeExistingApplication(?ActivityApplication $application): ?array
    {
        if (!$application) {
            return null;
        }

        return [
            'id' => $application->id,
            'selected_character_id' => $application->selected_character_id,
            'status' => $application->status,
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
     * @return array<int, array<string, mixed>>
     */
    private function applicationCharactersForUser(int $userId): array
    {
        return Character::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get()
            ->map(fn (Character $character) => [
                'id' => $character->id,
                'name' => $character->name,
                'avatar_url' => $character->avatar_url,
                'world' => $character->world,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateApplicationPayload(Request $request, Activity $activity, ?int $userId = null): array
    {
        $validated = $request->validate([
            'selected_character_id' => [$userId ? 'sometimes' : 'prohibited', 'nullable', 'integer', 'exists:characters,id'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'answers' => ['sometimes', 'array'],
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

            if (!$selectedCharacter || $selectedCharacter->user_id !== $userId) {
                throw ValidationException::withMessages([
                    'selected_character_id' => 'The selected character is invalid for this application.',
                ]);
            }
        }

        $validated['applicant'] = $selectedCharacter
            ? $this->characterApplicantSnapshot($selectedCharacter)
            : $this->guestApplicantSnapshot($validated['guest_applicant'] ?? []);

        if (!$userId) {
            $this->ensureGuestApplicantCanBeUsed($validated['applicant']);
        }

        $validated['answers'] = $this->normalizeApplicationAnswers(
            $validated['answers'] ?? [],
            $activity->activityTypeVersion
        );

        $requiredQuestionKeys = collect($activity->activityTypeVersion?->application_schema ?? [])
            ->filter(fn ($question) => is_array($question) && filled($question['key'] ?? null) && (bool) ($question['required'] ?? false))
            ->pluck('key')
            ->map(fn ($key) => (string) $key);

        $answersByKey = collect($validated['answers'])->keyBy('question_key');

        foreach ($requiredQuestionKeys as $questionKey) {
            $answer = $answersByKey->get($questionKey);
            $value = $answer['value'] ?? null;

            $isEmpty = match (true) {
                is_array($value) => count(array_filter($value, fn ($entry) => !blank($entry))) === 0,
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

        if (!$character) {
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
            ->firstOrFail();
    }

    private function confirmationSessionKey(int $activityId): string
    {
        return sprintf('activities.%d.application_confirmation', $activityId);
    }

    private function applicationIsEditable(ActivityApplication $application): bool
    {
        return $application->status === ActivityApplication::STATUS_PENDING;
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
