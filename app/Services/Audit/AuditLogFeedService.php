<?php

namespace App\Services\Audit;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class AuditLogFeedService
{
    public function paginate(Builder $baseQuery, array $filters, ?string $cursor): CursorPaginator
    {
        $query = clone $baseQuery;

        foreach (['action', 'severity'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        if (filled($filters['user'] ?? null)) {
            $filters['user'] === '__system__'
                ? $query->whereNull('actor_user_id')
                : $query->where('actor_user_id', $filters['user']);
        }

        if (filled($filters['group'] ?? null)) {
            $query->where('scope_type', 'group')->where('scope_id', $filters['group']);
        }

        if (filled($filters['afterDate'] ?? null)) {
            $query->where('created_at', '>=', Carbon::parse($filters['afterDate'])->startOfDay());
        }

        if (filled($filters['beforeDate'] ?? null)) {
            $query->where('created_at', '<', Carbon::parse($filters['beforeDate'])->addDay()->startOfDay());
        }

        if (filled($filters['activity'] ?? null)) {
            $activityId = (int) $filters['activity'];
            // Legacy records can only be linked when they retain an exact model reference.
            $query->where(function (Builder $query) use ($activityId) {
                $query->where('metadata->activity_id', $activityId)
                    ->orWhere(fn (Builder $subject) => $subject
                        ->where('subject_type', Activity::class)->where('subject_id', $activityId))
                    ->orWhereHasMorph('subject', [ActivityApplication::class, ActivitySlot::class],
                        fn (Builder $subject) => $subject->where('activity_id', $activityId));
            });
        }

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $this->search($query, $search);
        }

        return $query->with(['actor', 'subject'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(40, ['*'], 'cursor', $cursor);
    }

    public function options(Builder $baseQuery, ?Group $group = null): array
    {
        $users = User::query()->where(function (Builder $users) use ($baseQuery, $group) {
            $users->whereIn('id', (clone $baseQuery)->select('actor_user_id')->whereNotNull('actor_user_id'));
            if ($group) {
                $users->orWhereIn('id', $group->memberships()->select('user_id'));
            }
        })->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name]);

        if ((clone $baseQuery)->whereNull('actor_user_id')->exists()) {
            $users->prepend(['value' => '__system__', 'label' => __('audit_log.defaults.system')]);
        }

        $options = [
            'actions' => (clone $baseQuery)->select('action', 'message')->distinct()->get()
                ->unique('action')->map(fn ($log) => ['value' => $log->action, 'label' => $log->message])
                ->sortBy('label')->values(),
            'severities' => (clone $baseQuery)->select('severity')->distinct()->orderBy('severity')->pluck('severity')
                ->map(fn ($severity) => ['value' => $severity, 'label' => 'audit_log.severities.'.$severity]),
            'users' => $users->values(),
        ];

        if ($group) {
            $options['activities'] = Activity::query()->where('group_id', $group->id)
                ->orderByDesc('starts_at')->orderByDesc('id')->get(['id', 'title', 'starts_at'])
                ->map(fn (Activity $activity) => [
                    'value' => (string) $activity->id,
                    'title' => $activity->title,
                    'starts_at' => $activity->starts_at?->toIso8601String(),
                ]);
        } else {
            $options['groups'] = Group::query()->whereIn('id', (clone $baseQuery)
                ->where('scope_type', 'group')->select('scope_id'))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (Group $group) => ['value' => (string) $group->id, 'label' => $group->name]);
        }

        return $options;
    }

    private function search(Builder $query, string $search): void
    {
        $needle = mb_strtolower($search);
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';
        $translatedActions = [];
        foreach (['events', 'activity'] as $section) {
            $translations = __('audit_log.'.$section);
            foreach (Arr::dot(is_array($translations) ? $translations : []) as $action => $label) {
                if (is_string($label) && str_contains(mb_strtolower($label), $needle)) {
                    $translatedActions[] = $action;
                }
            }
        }

        $query->where(function (Builder $searchQuery) use ($pattern, $translatedActions, $needle) {
            foreach (['message', 'action', 'severity', 'scope_type', 'metadata'] as $column) {
                $searchQuery->orWhereRaw("LOWER(CAST({$column} AS TEXT)) LIKE ? ESCAPE '\\'", [$pattern]);
            }
            $searchQuery->orWhereIn('action', $translatedActions)
                ->orWhereHas('actor', fn (Builder $actor) => $actor->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$pattern]))
                ->orWhereHasMorph('subject', [User::class, Group::class, Character::class, Activity::class],
                    function (Builder $subject, string $type) use ($pattern) {
                        $column = $type === Activity::class ? 'title' : 'name';
                        $subject->whereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", [$pattern]);
                    });

            if (str_contains(mb_strtolower(__('audit_log.defaults.system')), $needle)) {
                $searchQuery->orWhereNull('actor_user_id');
            }
        });
    }
}
