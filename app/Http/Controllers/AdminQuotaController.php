<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\QuotaOverride;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Quotas\QuotaService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Quotas\QuotaKey;
use App\Support\Quotas\QuotaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminQuotaController extends Controller
{
    public function __construct(
        private readonly QuotaService $quotaService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(): Response
    {
        $this->authorizeAdminAccess();

        $overrides = QuotaOverride::query()
            ->with('creator:id,name')
            ->latest('updated_at')
            ->paginate(50)
            ->through(fn (QuotaOverride $override): array => $this->serializeOverride($override));

        return Inertia::render('Admin/Quotas', [
            'mode' => (string) config('quotas.mode'),
            'definitions' => collect(QuotaKey::values())
                ->map(fn (string $key): array => [
                    'key' => $key,
                    'scope' => QuotaKey::scope($key),
                    'label' => __(sprintf('quotas.labels.%s', str_replace('.', '_', $key))),
                    'default_limit' => $this->quotaService->defaultLimit($key),
                ])
                ->values(),
            'overrides' => $overrides,
        ]);
    }

    public function subjects(Request $request): JsonResponse
    {
        $this->authorizeAdminAccess();

        $validated = $request->validate([
            'type' => ['required', Rule::in([QuotaScope::USER, QuotaScope::GROUP])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));

        $subjects = match ($validated['type']) {
            QuotaScope::USER => User::query()
                ->select(['id', 'name', 'email'])
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")))
                ->orderBy('name')
                ->limit(25)
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'label' => $user->name,
                    'description' => $user->email,
                ]),
            QuotaScope::GROUP => Group::query()
                ->select(['id', 'name', 'slug'])
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")))
                ->orderBy('name')
                ->limit(25)
                ->get()
                ->map(fn (Group $group): array => [
                    'id' => $group->id,
                    'label' => $group->name,
                    'description' => $group->slug,
                ]),
        };

        return response()->json(['data' => $subjects->values()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdminAccess();
        $validated = $this->validateOverride($request, true);
        $subject = $this->resolveSubject($validated['subject_type'], (int) $validated['subject_id']);
        $this->assertQuotaMatchesSubject((string) $validated['quota_key'], $subject);

        $override = QuotaOverride::query()->updateOrCreate(
            [
                'subject_type' => $validated['subject_type'],
                'subject_id' => $subject->getKey(),
                'quota_key' => $validated['quota_key'],
            ],
            $this->overrideValues($validated, $request->user()->id),
        );

        $this->auditOverride($override->wasRecentlyCreated ? 'created' : 'updated', $override, $request->user());

        return back()->with('success', 'quota_override_saved');
    }

    public function update(Request $request, QuotaOverride $quotaOverride): RedirectResponse
    {
        $this->authorizeAdminAccess();
        $validated = $this->validateOverride($request, false);
        $quotaOverride->update($this->overrideValues($validated, $request->user()->id));
        $this->auditOverride('updated', $quotaOverride, $request->user());

        return back()->with('success', 'quota_override_saved');
    }

    public function destroy(Request $request, QuotaOverride $quotaOverride): RedirectResponse
    {
        $this->authorizeAdminAccess();
        $snapshot = $quotaOverride->only([
            'subject_type',
            'subject_id',
            'quota_key',
            'limit',
            'is_unlimited',
            'starts_at',
            'expires_at',
            'reason',
        ]);
        $quotaOverride->delete();

        $this->auditLogger->log(
            action: 'admin.quota_override.deleted',
            severity: AuditSeverity::SEVERE_CHANGE,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.quota_override.deleted',
            actor: $request->user(),
            metadata: $snapshot,
        );

        return back()->with('success', 'quota_override_deleted');
    }

    /** @return array<string, mixed> */
    private function validateOverride(Request $request, bool $includeSubject): array
    {
        $rules = [
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000000', 'required_unless:is_unlimited,true'],
            'is_unlimited' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => [
                'nullable',
                'date',
                filled($request->input('starts_at')) ? 'after:starts_at' : 'after:now',
            ],
            'reason' => ['required', 'string', 'max:1000'],
        ];

        if ($includeSubject) {
            $rules = [
                'subject_type' => ['required', Rule::in([QuotaScope::USER, QuotaScope::GROUP])],
                'subject_id' => ['required', 'integer', 'min:1'],
                'quota_key' => ['required', Rule::in(QuotaKey::values())],
                ...$rules,
            ];
        }

        return $request->validate($rules);
    }

    /** @param array<string, mixed> $validated */
    private function overrideValues(array $validated, int $actorId): array
    {
        return [
            'limit' => ($validated['is_unlimited'] ?? false) ? null : (int) $validated['limit'],
            'is_unlimited' => (bool) $validated['is_unlimited'],
            'starts_at' => $validated['starts_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'reason' => $validated['reason'],
            'created_by_user_id' => $actorId,
        ];
    }

    private function resolveSubject(string $scope, int $id): Model
    {
        return match ($scope) {
            QuotaScope::USER => User::query()->findOrFail($id),
            QuotaScope::GROUP => Group::query()->findOrFail($id),
            default => throw ValidationException::withMessages([
                'subject_type' => __('quotas.validation.unsupported_subject'),
            ]),
        };
    }

    private function assertQuotaMatchesSubject(string $key, Model $subject): void
    {
        if (QuotaKey::scope($key) !== $this->quotaService->subjectScope($subject)) {
            throw ValidationException::withMessages([
                'quota_key' => __('quotas.validation.scope_mismatch'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function serializeOverride(QuotaOverride $override): array
    {
        $subject = $this->findSubject($override->subject_type, $override->subject_id);
        $status = $subject === null
            ? null
            : $this->quotaService->status($override->quota_key, $subject);

        return [
            'id' => $override->id,
            'subject_type' => $override->subject_type,
            'subject_id' => $override->subject_id,
            'subject_label' => $subject === null
                ? __('quotas.deleted_subject', ['id' => $override->subject_id])
                : $this->subjectLabel($subject),
            'quota_key' => $override->quota_key,
            'quota_label' => __(sprintf('quotas.labels.%s', str_replace('.', '_', $override->quota_key))),
            'limit' => $override->limit,
            'is_unlimited' => $override->is_unlimited,
            'usage' => $status['usage'] ?? null,
            'starts_at' => $override->starts_at?->toIso8601String(),
            'expires_at' => $override->expires_at?->toIso8601String(),
            'reason' => $override->reason,
            'created_by' => $override->creator?->name,
            'updated_at' => $override->updated_at?->toIso8601String(),
        ];
    }

    private function subjectLabel(Model $subject): string
    {
        return match (true) {
            $subject instanceof User => "{$subject->name} ({$subject->email})",
            $subject instanceof Group => "{$subject->name} ({$subject->slug})",
            default => (string) $subject->getKey(),
        };
    }

    private function findSubject(string $scope, int $id): ?Model
    {
        return match ($scope) {
            QuotaScope::USER => User::query()->find($id),
            QuotaScope::GROUP => Group::query()->find($id),
            default => null,
        };
    }

    private function auditOverride(string $action, QuotaOverride $override, User $actor): void
    {
        $this->auditLogger->log(
            action: "admin.quota_override.{$action}",
            severity: AuditSeverity::SEVERE_CHANGE,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: "audit_log.events.admin.quota_override.{$action}",
            actor: $actor,
            subject: $override,
            metadata: $override->only([
                'subject_type',
                'subject_id',
                'quota_key',
                'limit',
                'is_unlimited',
                'starts_at',
                'expires_at',
                'reason',
            ]),
        );
    }

    private function authorizeAdminAccess(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }
}
