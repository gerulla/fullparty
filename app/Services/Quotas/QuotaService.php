<?php

namespace App\Services\Quotas;

use App\DTOs\QuotaCheck;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\BozjaHolster;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\GroupMembershipApplication;
use App\Models\GroupUserNote;
use App\Models\GroupUserNoteAddendum;
use App\Models\PhantomComposition;
use App\Models\QuotaOverride;
use App\Models\User;
use App\Support\Quotas\QuotaKey;
use App\Support\Quotas\QuotaScope;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class QuotaService
{
    public function assert(QuotaCheck $check): void
    {
        $this->assertCheck($check);
    }

    /**
     * Execute a write while holding locks for every quota subject involved.
     *
     * @template TReturn
     *
     * @param  array<int, QuotaCheck>  $checks
     * @param  Closure(): TReturn  $operation
     * @return TReturn
     */
    public function run(array $checks, Closure $operation): mixed
    {
        return $this->runIf($checks, static fn (): bool => true, $operation);
    }

    /**
     * Execute a write while only consuming quota when the locked state requires it.
     *
     * @template TReturn
     *
     * @param  array<int, QuotaCheck>  $checks
     * @param  Closure(): bool  $shouldConsume
     * @param  Closure(): TReturn  $operation
     * @return TReturn
     */
    public function runIf(array $checks, Closure $shouldConsume, Closure $operation): mixed
    {
        return DB::transaction(function () use ($checks, $shouldConsume, $operation): mixed {
            $this->lockSubjects($checks);

            if ($shouldConsume()) {
                foreach ($checks as $check) {
                    $this->assertCheck($check);
                }
            }

            return $operation();
        });
    }

    /**
     * @return array{key: string, scope: string, usage: int|null, limit: int|null, unlimited: bool, overridden: bool, exceeded: bool|null}
     */
    public function status(string $key, Model $subject, array $context = [], int $amount = 0): array
    {
        $scope = $this->assertValidSubject($key, $subject);
        $override = $this->activeOverride($key, $scope, (int) $subject->getKey());
        $unlimited = (bool) $override?->is_unlimited;
        $limit = $unlimited
            ? null
            : ($override?->limit ?? $this->defaultLimit($key));
        $usage = $this->usage($key, $subject, $context);

        return [
            'key' => $key,
            'scope' => $scope,
            'usage' => $usage,
            'limit' => $limit,
            'unlimited' => $unlimited,
            'overridden' => $override !== null,
            'exceeded' => $usage === null || $limit === null
                ? null
                : ($usage + $amount > $limit),
        ];
    }

    public function defaultLimit(string $key): int
    {
        if (! in_array($key, QuotaKey::values(), true)) {
            throw new InvalidArgumentException("Unknown quota key [{$key}].");
        }

        $limits = config('quotas.limits', []);
        $limit = is_array($limits) ? ($limits[$key] ?? null) : null;

        if (! is_int($limit) || $limit < 1) {
            throw new InvalidArgumentException("Quota [{$key}] must have a positive integer limit.");
        }

        return $limit;
    }

    public function isEnforced(): bool
    {
        return config('quotas.mode') === 'enforce';
    }

    public function runDay(Group $group, mixed $startsAt): ?string
    {
        if (blank($startsAt)) {
            return null;
        }

        return CarbonImmutable::parse($startsAt, 'UTC')
            ->utc()
            ->setTimezone($this->groupTimezone($group))
            ->toDateString();
    }

    public function subjectScope(Model $subject): string
    {
        return match (true) {
            $subject instanceof User => QuotaScope::USER,
            $subject instanceof Group => QuotaScope::GROUP,
            default => throw new InvalidArgumentException('Unsupported quota subject type ['.$subject::class.'].'),
        };
    }

    private function assertCheck(QuotaCheck $check): void
    {
        if ($check->amount < 1) {
            throw new InvalidArgumentException('Quota consumption amount must be at least one.');
        }

        $status = $this->status($check->key, $check->subject, $check->context, $check->amount);

        if ($status['exceeded'] !== true) {
            return;
        }

        Log::warning('Quota limit reached.', [
            'quota_key' => $check->key,
            'subject_type' => $status['scope'],
            'subject_id' => $check->subject->getKey(),
            'usage' => $status['usage'],
            'amount' => $check->amount,
            'limit' => $status['limit'],
            'mode' => config('quotas.mode'),
            'actor_user_id' => auth()->id(),
        ]);

        if (! $this->isEnforced()) {
            return;
        }

        $translationKey = str_replace('.', '_', $check->key);

        throw ValidationException::withMessages([
            'quota' => __("quotas.messages.{$translationKey}", [
                'limit' => $status['limit'],
            ]),
        ]);
    }

    /** @param array<int, QuotaCheck> $checks */
    private function lockSubjects(array $checks): void
    {
        collect($checks)
            ->map(fn (QuotaCheck $check): Model => $check->subject)
            ->unique(fn (Model $subject): string => $subject::class.':'.$subject->getKey())
            ->sortBy(fn (Model $subject): string => $subject::class.':'.str_pad((string) $subject->getKey(), 20, '0', STR_PAD_LEFT))
            ->each(function (Model $subject): void {
                $subject->newQuery()
                    ->whereKey($subject->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
            });
    }

    private function assertValidSubject(string $key, Model $subject): string
    {
        $expectedScope = QuotaKey::scope($key);

        if ($expectedScope === null) {
            throw new InvalidArgumentException("Unknown quota key [{$key}].");
        }

        $actualScope = $this->subjectScope($subject);

        if ($expectedScope !== $actualScope) {
            throw new InvalidArgumentException("Quota [{$key}] requires a [{$expectedScope}] subject, [{$actualScope}] given.");
        }

        return $actualScope;
    }

    private function activeOverride(string $key, string $scope, int $subjectId): ?QuotaOverride
    {
        return QuotaOverride::query()
            ->active()
            ->where('subject_type', $scope)
            ->where('subject_id', $subjectId)
            ->where('quota_key', $key)
            ->first();
    }

    private function usage(string $key, Model $subject, array $context): ?int
    {
        return match ($key) {
            QuotaKey::GROUPS_OWNED => Group::query()->where('owner_id', $subject->getKey())->count(),
            QuotaKey::GROUPS_JOINED => $subject->groupMemberships()->count(),
            QuotaKey::RUNS_PER_DAY => $this->runsOnDay($subject, $context),
            QuotaKey::FUTURE_RUNS => $this->futureRuns($subject),
            QuotaKey::CHARACTERS_TOTAL => Character::query()->where('user_id', $subject->getKey())->count(),
            QuotaKey::ACTIVE_APPLICATIONS => ActivityApplication::query()
                ->where('user_id', $subject->getKey())
                ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)
                ->whereHas('activity', fn ($query) => $query
                    ->whereNotIn('status', Activity::ARCHIVED_STATUSES)
                    ->where(fn ($query) => $query
                        ->whereNull('starts_at')
                        ->orWhere('starts_at', '>=', now())))
                ->count(),
            QuotaKey::PENDING_MEMBERSHIP_APPLICATIONS => GroupMembershipApplication::query()
                ->where('user_id', $subject->getKey())
                ->where('status', GroupMembershipApplication::STATUS_PENDING)
                ->count(),
            QuotaKey::ACTIVE_INVITES => $this->activeInvites($subject),
            QuotaKey::HOLSTERS_TOTAL => BozjaHolster::query()->where('group_id', $subject->getKey())->count(),
            QuotaKey::PHANTOM_COMPOSITIONS_TOTAL => PhantomComposition::query()->where('group_id', $subject->getKey())->count(),
            QuotaKey::MEMBER_NOTES_PER_MEMBER => $this->memberNotes($subject, $context),
            QuotaKey::MEMBER_NOTE_ADDENDA_PER_NOTE => $this->noteAddenda($subject, $context),
            QuotaKey::RUN_LIST_GROUPS_TOTAL => $subject->runListGroups()->count(),
            default => throw new InvalidArgumentException("No usage counter exists for quota [{$key}]."),
        };
    }

    private function runsOnDay(Group $group, array $context): ?int
    {
        if (blank($context['starts_at'] ?? null)) {
            return null;
        }

        $timezone = $this->groupTimezone($group);
        $startsAt = CarbonImmutable::parse($context['starts_at'], 'UTC')->utc();
        $localDate = $startsAt->setTimezone($timezone);
        $dayStart = $localDate->startOfDay()->utc();
        $dayEnd = $localDate->addDay()->startOfDay()->utc();

        return Activity::query()
            ->where('group_id', $group->id)
            ->where('status', '!=', Activity::STATUS_CANCELLED)
            ->where('starts_at', '>=', $dayStart)
            ->where('starts_at', '<', $dayEnd)
            ->when(
                filled($context['exclude_activity_id'] ?? null),
                fn ($query) => $query->whereKeyNot((int) $context['exclude_activity_id']),
            )
            ->count();
    }

    private function futureRuns(Group $group): int
    {
        return Activity::query()
            ->where('group_id', $group->id)
            ->whereNotIn('status', Activity::ARCHIVED_STATUSES)
            ->where(fn ($query) => $query
                ->whereNull('starts_at')
                ->orWhere('starts_at', '>=', now()->subDay()))
            ->count();
    }

    private function activeInvites(Group $group): int
    {
        return GroupInvite::query()
            ->where('group_id', $group->id)
            ->where('is_system', false)
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query
                ->whereNull('max_uses')
                ->orWhereColumn('uses', '<', 'max_uses'))
            ->count();
    }

    private function memberNotes(Group $group, array $context): ?int
    {
        if (! isset($context['user_id'])) {
            return null;
        }

        return GroupUserNote::query()
            ->where('group_id', $group->id)
            ->where('user_id', (int) $context['user_id'])
            ->count();
    }

    private function noteAddenda(Group $group, array $context): ?int
    {
        if (! isset($context['note_id'])) {
            return null;
        }

        return GroupUserNoteAddendum::query()
            ->where('group_user_note_id', (int) $context['note_id'])
            ->whereHas('note', fn ($query) => $query->where('group_id', $group->id))
            ->count();
    }

    private function groupTimezone(Group $group): string
    {
        $timezone = filled($group->active_timezone) ? (string) $group->active_timezone : 'UTC';

        try {
            CarbonImmutable::now($timezone);

            return $timezone;
        } catch (Throwable) {
            return 'UTC';
        }
    }
}
