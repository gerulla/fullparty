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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GroupActivityApplicationController extends Controller
{
    use InteractsWithGroupActivityAttendees;

    public function __construct(
        private readonly GroupActivityAuditService $activityAuditService,
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
            ? $activity->applications->firstWhere('user_id', $request->user()->id)
            : null;

        return Inertia::render('Groups/Activities/Application', [
            'group' => $this->serializePublicGroup($group),
            'activity' => $this->serializeAttendeeActivity($activity),
            'applicationSchema' => $this->serializeApplicationSchema($activity->activityTypeVersion),
            'application' => $this->serializeExistingApplication($existingApplication),
            'characters' => $request->user()
                ? $this->applicationCharactersForUser($request->user()->id)
                : [],
            'permissions' => [
                'can_apply' => $request->user() !== null,
                'can_manage' => $group->hasModeratorAccess($request->user()?->id),
                'has_existing_application' => $existingApplication !== null,
            ],
        ]);
    }

    public function store(Request $request, Group $group, Activity $activity, ?string $secretKey = null): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->ensureApplicationPageAccessible($request, $group, $activity, $secretKey);

        $user = $request->user();

        if (!$user) {
            abort(403);
        }

        $activity->loadMissing('activityTypeVersion');

        if ($activity->applications()->where('user_id', $user->id)->exists()) {
            abort(422, 'You have already submitted an application for this activity.');
        }

        $validated = $this->validateApplicationPayload($request, $activity, $user->id);

        DB::transaction(function () use ($activity, $user, $validated) {
            $application = $activity->applications()->create([
                'user_id' => $user->id,
                'selected_character_id' => $validated['selected_character_id'] ?? null,
                'status' => ActivityApplication::STATUS_PENDING,
                'notes' => $validated['notes'] ?? null,
                'reviewed_by_user_id' => null,
                'submitted_at' => now(),
                'reviewed_at' => null,
            ]);

            $this->syncApplicationAnswers($application, $validated['answers'] ?? []);
            $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);
            $this->activityAuditService->logApplicationSubmitted($application, $user);
        });

        return redirect()
            ->route('groups.activities.application', $this->activityAttendeeRouteParameters($group, $activity, $secretKey))
            ->with('success', 'activity_application_submitted');
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
            ->first();

        if (!$application) {
            abort(404);
        }

        $validated = $this->validateApplicationPayload($request, $activity, $user->id);

        DB::transaction(function () use ($application, $validated) {
            $application->update([
                'selected_character_id' => $validated['selected_character_id'] ?? null,
                'status' => ActivityApplication::STATUS_PENDING,
                'notes' => $validated['notes'] ?? null,
                'reviewed_by_user_id' => null,
                'submitted_at' => now(),
                'reviewed_at' => null,
            ]);

            $this->syncApplicationAnswers($application, $validated['answers'] ?? []);
            $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);
            $this->activityAuditService->logApplicationUpdated($application, auth()->user());
        });

        return redirect()
            ->route('groups.activities.application', $this->activityAttendeeRouteParameters($group, $activity, $secretKey))
            ->with('success', 'activity_application_updated');
    }

    private function ensureApplicationPageAccessible(Request $request, Group $group, Activity $activity, ?string $secretKey): void
    {
        $this->ensureActivityBelongsToGroup($group, $activity);

        if (!$this->canAccessOverview($request, $group, $activity, $secretKey)) {
            abort(404);
        }

        if (!$activity->needs_application || $activity->isArchived()) {
            abort(404);
        }
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
    private function validateApplicationPayload(Request $request, Activity $activity, int $userId): array
    {
        $validated = $request->validate([
            'selected_character_id' => ['sometimes', 'nullable', 'integer', 'exists:characters,id'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'answers' => ['sometimes', 'array'],
        ]);

        $characterId = $validated['selected_character_id'] ?? null;

        if ($characterId) {
            $character = Character::query()->find($characterId);

            if (!$character || $character->user_id !== $userId) {
                throw ValidationException::withMessages([
                    'selected_character_id' => 'The selected character is invalid for this application.',
                ]);
            }
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
