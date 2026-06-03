<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Models\User;
use App\Services\Groups\ActivityApplicationWithdrawalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountApplicationController extends Controller
{
    private const ACTIVE_CACHE_SECONDS = 300;

    private const HISTORY_CACHE_SECONDS = 3600;

    private const HISTORY_PER_PAGE_OPTIONS = [10, 25, 50];

    private const UPCOMING_ACTIVE_STATUSES = [
        ActivityApplication::STATUS_PENDING,
        ActivityApplication::STATUS_APPROVED,
        ActivityApplication::STATUS_ON_BENCH,
    ];

    private const UPCOMING_CANCELLED_STATUSES = [
        ActivityApplication::STATUS_DECLINED,
        ActivityApplication::STATUS_CANCELLED,
        ActivityApplication::STATUS_WITHDRAWN,
    ];

    public function __construct(
        private readonly ActivityApplicationWithdrawalService $applicationWithdrawalService,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $payload = Cache::remember(
            $this->activeCacheKey($user),
            self::ACTIVE_CACHE_SECONDS,
            fn (): array => $this->activePayload($user),
        );

        return Inertia::render('Dashboard/Account/MyApplications', [
            ...$payload,
            'historyPerPageOptions' => self::HISTORY_PER_PAGE_OPTIONS,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in(self::HISTORY_PER_PAGE_OPTIONS)],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? self::HISTORY_PER_PAGE_OPTIONS[0]);

        $payload = Cache::remember(
            $this->historyCacheKey($user, $page, $perPage),
            self::HISTORY_CACHE_SECONDS,
            fn (): array => $this->historyPayload($user, $page, $perPage),
        );

        return response()->json($payload);
    }

    public function destroy(Request $request, ActivityApplication $application): RedirectResponse
    {
        $user = $request->user();
        $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);

        if ((int) $application->user_id !== (int) $user->id) {
            abort(404);
        }

        if (! $this->applicationWithdrawalService->applicationCanBeWithdrawn($application->activity, $application)) {
            throw ValidationException::withMessages([
                'application' => 'This application cannot be withdrawn.',
            ]);
        }

        $this->applicationWithdrawalService->withdraw($application, $user);

        return redirect()->route('account.applications');
    }

    /**
     * @return array{featuredApplication: array<string, mixed>|null, activeApplications: array<int, array<string, mixed>>, cancelledApplications: array<int, array<string, mixed>>, hasHistoricalApplications: bool}
     */
    private function activePayload(User $user): array
    {
        $now = now();

        $activeApplications = $this->orderedUpcomingQuery($user)
            ->whereIn('activity_applications.status', self::UPCOMING_ACTIVE_STATUSES)
            ->where(function (Builder $query) {
                $query->whereNull('activities.status')
                    ->orWhereNotIn('activities.status', Activity::ARCHIVED_STATUSES);
            })
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('activities.starts_at')
                    ->orWhere('activities.starts_at', '>=', $now);
            })
            ->get();

        $serializedActive = $this->serializeApplications($activeApplications);
        $featuredApplication = array_shift($serializedActive);

        $cancelledApplications = $this->orderedUpcomingQuery($user)
            ->whereIn('activity_applications.status', self::UPCOMING_CANCELLED_STATUSES)
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('activities.starts_at')
                    ->orWhere('activities.starts_at', '>=', $now);
            })
            ->get();

        return [
            'featuredApplication' => $featuredApplication,
            'activeApplications' => $serializedActive,
            'cancelledApplications' => $this->serializeApplications($cancelledApplications),
            'hasHistoricalApplications' => $this->historyQuery($user)->exists(),
        ];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int|null>}
     */
    private function historyPayload(User $user, int $page, int $perPage): array
    {
        $paginator = $this->historyQuery($user)
            ->orderByRaw('activities.starts_at IS NULL')
            ->orderByDesc('activities.starts_at')
            ->orderByDesc('activity_applications.submitted_at')
            ->orderByDesc('activity_applications.id')
            ->paginate($perPage, ['activity_applications.*'], 'page', $page);

        return [
            'data' => $this->serializeApplications($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function orderedUpcomingQuery(User $user): Builder
    {
        return $this->applicationQuery($user)
            ->orderByRaw('activities.starts_at IS NULL')
            ->orderBy('activities.starts_at')
            ->orderByDesc('activity_applications.submitted_at')
            ->orderByDesc('activity_applications.id');
    }

    private function historyQuery(User $user): Builder
    {
        $now = now();

        return $this->applicationQuery($user)
            ->where(function (Builder $query) use ($now) {
                $query->where(function (Builder $query) {
                    $query->whereNotIn('activity_applications.status', [
                        ...self::UPCOMING_ACTIVE_STATUSES,
                        ...self::UPCOMING_CANCELLED_STATUSES,
                    ]);
                })
                    ->orWhere(function (Builder $query) use ($now) {
                        $query->whereIn('activity_applications.status', self::UPCOMING_ACTIVE_STATUSES)
                            ->where(function (Builder $query) use ($now) {
                                $query->whereNull('activities.id')
                                    ->orWhereIn('activities.status', Activity::ARCHIVED_STATUSES)
                                    ->orWhere(function (Builder $query) use ($now) {
                                        $query->whereNotNull('activities.starts_at')
                                            ->where('activities.starts_at', '<', $now);
                                    });
                            });
                    })
                    ->orWhere(function (Builder $query) use ($now) {
                        $query->whereIn('activity_applications.status', self::UPCOMING_CANCELLED_STATUSES)
                            ->where(function (Builder $query) use ($now) {
                                $query->whereNull('activities.id')
                                    ->orWhere(function (Builder $query) use ($now) {
                                        $query->whereNotNull('activities.starts_at')
                                            ->where('activities.starts_at', '<', $now);
                                    });
                            });
                    });
            });
    }

    private function applicationQuery(User $user): Builder
    {
        return ActivityApplication::query()
            ->select('activity_applications.*')
            ->with([
                'activity.group',
                'activity.activityTypeVersion',
                'selectedCharacter',
            ])
            ->leftJoin('activities', 'activities.id', '=', 'activity_applications.activity_id')
            ->where('activity_applications.user_id', $user->id);
    }

    /**
     * @param  EloquentCollection<int, ActivityApplication>  $applications
     * @return array<int, array<string, mixed>>
     */
    private function serializeApplications(EloquentCollection $applications): array
    {
        $assignments = $this->activeAssignmentsFor($applications);

        return $applications
            ->map(fn (ActivityApplication $application) => $this->serializeApplication(
                $application,
                $assignments->get($application->id),
            ))
            ->values()
            ->all();
    }

    /**
     * @param  EloquentCollection<int, ActivityApplication>  $applications
     * @return EloquentCollection<int, ActivitySlotAssignment>
     */
    private function activeAssignmentsFor(EloquentCollection $applications): EloquentCollection
    {
        $applicationIds = $applications
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if ($applicationIds === []) {
            return new EloquentCollection;
        }

        return ActivitySlotAssignment::query()
            ->with(['slot.fieldValues'])
            ->whereIn('application_id', $applicationIds)
            ->whereNull('ended_at')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get()
            ->unique('application_id')
            ->keyBy('application_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApplication(ActivityApplication $application, ?ActivitySlotAssignment $assignment = null): array
    {
        $activity = $application->activity;
        $character = $application->selectedCharacter;
        $canEdit = $this->applicationCanBeModified($application);
        $canWithdraw = $activity
            ? $this->applicationWithdrawalService->applicationCanBeWithdrawn($activity, $application)
            : false;
        $assignmentPayload = $this->serializeAssignment($assignment);
        $targetProgPoint = $this->serializeTargetProgPoint($activity);

        return [
            'id' => $application->id,
            'status' => $application->status,
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'review_reason' => $application->review_reason,
            'notes' => $application->notes,
            'can_edit' => $canEdit,
            'can_withdraw' => $canWithdraw,
            'is_rostered' => $assignmentPayload !== null || $this->applicationWithdrawalService->applicationIsRostered($application),
            'assignment' => $assignmentPayload,
            'group' => [
                'name' => $activity?->group?->name,
                'slug' => $activity?->group?->slug,
            ],
            'activity' => [
                'id' => $activity?->id,
                'title' => $activity?->title,
                'description' => $activity?->description,
                'status' => $activity?->status,
                'starts_at' => $activity?->starts_at?->toIso8601String(),
                'duration_hours' => $activity?->duration_hours,
                'is_public' => (bool) ($activity?->is_public ?? false),
                'type_name' => $activity?->activityTypeVersion?->name,
                'target_prog_point_key' => $targetProgPoint['key'],
                'target_prog_point_label' => $targetProgPoint['label'],
            ],
            'character' => [
                'name' => $character?->name ?? $application->applicant_character_name,
                'world' => $character?->world ?? $application->applicant_world,
                'datacenter' => $character?->datacenter ?? $application->applicant_datacenter,
                'avatar_url' => $character?->avatar_url ?? $application->applicant_avatar_url,
            ],
        ];
    }

    /**
     * @return array{key: string|null, label: array<string, string>|null}
     */
    private function serializeTargetProgPoint(?Activity $activity): array
    {
        $key = $activity?->target_prog_point_key;

        if (! filled($key)) {
            return ['key' => null, 'label' => null];
        }

        $progPoint = collect($activity?->activityTypeVersion?->prog_points ?? [])
            ->firstWhere('key', $key);

        return [
            'key' => $key,
            'label' => is_array($progPoint) && is_array($progPoint['label'] ?? null)
                ? $progPoint['label']
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeAssignment(?ActivitySlotAssignment $assignment): ?array
    {
        if (! $assignment || ! $assignment->slot) {
            return null;
        }

        return [
            'group_key' => $assignment->slot->group_key,
            'group_label' => $assignment->slot->group_label,
            'slot_key' => $assignment->slot->slot_key,
            'slot_label' => $assignment->slot->slot_label,
            'character_class' => $this->serializeCharacterClass($this->assignmentFieldValue($assignment, 'character_class') ?? $this->firstSnapshotItem($assignment, 'class')),
            'phantom_job' => $this->serializePhantomJob($this->assignmentFieldValue($assignment, 'phantom_job') ?? $this->firstSnapshotItem($assignment, 'phantom_job')),
            'raid_position' => $this->serializeRaidPosition($this->assignmentFieldValue($assignment, 'raid_position')),
            'attendance_status' => $assignment->attendance_status,
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assignmentFieldValue(ActivitySlotAssignment $assignment, string $key): ?array
    {
        $snapshot = is_array($assignment->field_values_snapshot)
            ? $assignment->field_values_snapshot
            : [];

        $snapshotValue = $snapshot[$key] ?? null;

        if (is_array($snapshotValue)) {
            return $snapshotValue;
        }

        $slotValue = $assignment->slot?->fieldValues?->firstWhere('field_key', $key)?->value;

        return is_array($slotValue) ? $slotValue : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstSnapshotItem(ActivitySlotAssignment $assignment, string $kind): ?array
    {
        $snapshot = is_array($assignment->field_values_snapshot)
            ? $assignment->field_values_snapshot
            : [];

        foreach ($snapshot as $fieldKey => $value) {
            if (! is_array($value)) {
                continue;
            }

            $values = array_is_list($value) && isset($value[0]) && is_array($value[0])
                ? $value
                : [$value];

            foreach ($values as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                if ($kind === 'class' && $this->isClassSnapshotEntry((string) $fieldKey, $entry)) {
                    return $entry;
                }

                if ($kind === 'phantom_job' && $this->isPhantomJobSnapshotEntry((string) $fieldKey, $entry)) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isClassSnapshotEntry(string $fieldKey, array $entry): bool
    {
        return str_contains($fieldKey, 'class')
            || array_key_exists('role', $entry)
            || array_key_exists('shorthand', $entry);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isPhantomJobSnapshotEntry(string $fieldKey, array $entry): bool
    {
        return str_contains($fieldKey, 'phantom')
            || (str_contains($fieldKey, 'job') && ! $this->isClassSnapshotEntry($fieldKey, $entry));
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{id: int|null, name: string|null, shorthand: string|null, role: string|null}|null
     */
    private function serializeCharacterClass(?array $value): ?array
    {
        if (! $value) {
            return null;
        }

        $characterClass = filled($value['id'] ?? null)
            ? CharacterClass::query()->select(['id', 'name', 'shorthand', 'role'])->find((int) $value['id'])
            : null;

        return [
            'id' => $characterClass?->id ?? (isset($value['id']) ? (int) $value['id'] : null),
            'name' => $characterClass?->name ?? ($value['name'] ?? $value['label'] ?? null),
            'shorthand' => $characterClass?->shorthand ?? ($value['shorthand'] ?? null),
            'role' => $characterClass?->role ?? ($value['role'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{id: int|null, name: string|null}|null
     */
    private function serializePhantomJob(?array $value): ?array
    {
        if (! $value) {
            return null;
        }

        $phantomJob = filled($value['id'] ?? null)
            ? PhantomJob::query()->select(['id', 'name'])->find((int) $value['id'])
            : null;

        return [
            'id' => $phantomJob?->id ?? (isset($value['id']) ? (int) $value['id'] : null),
            'name' => $phantomJob?->name ?? ($value['name'] ?? $value['label'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{key: string|null, label: array<string, string>|string|null}|null
     */
    private function serializeRaidPosition(?array $value): ?array
    {
        if (! $value) {
            return null;
        }

        return [
            'key' => isset($value['key']) ? (string) $value['key'] : ($value['value'] ?? null),
            'label' => $value['label'] ?? null,
        ];
    }

    private function applicationCanBeModified(ActivityApplication $application): bool
    {
        $activity = $application->activity;

        if (! $activity) {
            return false;
        }

        return $application->status === ActivityApplication::STATUS_PENDING
            && $activity->needs_application
            && ! Activity::isArchivedStatus($activity->status)
            && ! $this->applicationWithdrawalService->applicationIsRostered($application);
    }

    private function activeCacheKey(User $user): string
    {
        return implode(':', [
            'account-applications',
            'active',
            $user->id,
            intdiv(now()->timestamp, self::ACTIVE_CACHE_SECONDS),
            $this->applicationCacheVersion($user),
        ]);
    }

    private function historyCacheKey(User $user, int $page, int $perPage): string
    {
        return implode(':', [
            'account-applications',
            'history',
            $user->id,
            $page,
            $perPage,
            intdiv(now()->timestamp, self::HISTORY_CACHE_SECONDS),
            $this->applicationCacheVersion($user),
        ]);
    }

    private function applicationCacheVersion(User $user): string
    {
        $row = ActivityApplication::query()
            ->leftJoin('activities', 'activities.id', '=', 'activity_applications.activity_id')
            ->where('activity_applications.user_id', $user->id)
            ->selectRaw('COUNT(*) as application_count, MAX(activity_applications.updated_at) as application_updated_at, MAX(activities.updated_at) as activity_updated_at')
            ->first();

        return md5(json_encode([
            'count' => (int) ($row?->application_count ?? 0),
            'application_updated_at' => (string) ($row?->application_updated_at ?? ''),
            'activity_updated_at' => (string) ($row?->activity_updated_at ?? ''),
        ], JSON_THROW_ON_ERROR));
    }
}
