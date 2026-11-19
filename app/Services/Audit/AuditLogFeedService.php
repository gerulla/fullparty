<?php

namespace App\Services\Audit;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivityType;
use App\Models\BozjaItem;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\CharacterFieldDefinition;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\PhantomJob;
use App\Models\RaidPosition;
use App\Models\SurveyForm;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class AuditLogFeedService
{
    public function paginate(Builder $baseQuery, array $filters, ?string $cursor, bool $siteWide = false): CursorPaginator
    {
        $query = clone $baseQuery;

        foreach (['action', 'severity'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        if ($siteWide && filled($filters['scope'] ?? null)) {
            $query->where('scope_type', $filters['scope']);
        }

        if (filled($filters['user'] ?? null)) {
            $filters['user'] === '__system__'
                ? $query->whereNull('actor_user_id')
                : $query->where('actor_user_id', $filters['user']);
        }

        if (filled($filters['group'] ?? null)) {
            $query->where('scope_type', 'group')->where('scope_id', $filters['group']);
        }

        if (filled($filters['category'] ?? null)) {
            GroupAuditCategory::apply($query, $filters['category']);
        }

        $timezone = $filters['timezone'] ?? 'UTC';

        if (filled($filters['afterDate'] ?? null)) {
            $query->where('created_at', '>=', Carbon::parse($filters['afterDate'], $timezone)->startOfDay()->utc());
        }

        if (filled($filters['beforeDate'] ?? null)) {
            $query->where('created_at', '<', Carbon::parse($filters['beforeDate'], $timezone)->addDay()->startOfDay()->utc());
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
            $this->search($query, $search, $siteWide);
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
            'actions' => (clone $baseQuery)->distinct()->pluck('action')
                ->map(function (string $action) {
                    $key = 'audit_log.events.'.$action;
                    $label = Lang::get($key);

                    return ['value' => $action, 'label' => is_string($label) && $label !== $key ? $key : Str::headline(str_replace('.', ' ', $action))];
                })
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

    private function search(Builder $query, string $search, bool $siteWide): void
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

        $query->where(function (Builder $searchQuery) use ($pattern, $translatedActions, $needle, $siteWide) {
            foreach (['message', 'action', 'severity', 'scope_type'] as $column) {
                $searchQuery->orWhereRaw("LOWER(CAST({$column} AS TEXT)) LIKE ? ESCAPE '\\'", [$pattern]);
            }
            $searchQuery->orWhere(function (Builder $metadata) use ($siteWide, $pattern) {
                if (! $siteWide) {
                    // A group search must respect the resource-specific presentation policy.
                    $metadata->where('action', 'not like', 'group.resources.%');
                }
                // Hidden credential values must not be discoverable through search results.
                foreach (AuditValuePresenter::SENSITIVE_FRAGMENTS as $fragment) {
                    $metadata->whereRaw('LOWER(CAST(metadata AS TEXT)) NOT LIKE ?', ['%'.$fragment.'%']);
                }
                $metadata->whereRaw("LOWER(CAST(metadata AS TEXT)) LIKE ? ESCAPE '\\'", [$pattern]);
            });
            $searchQuery->orWhereIn('action', $translatedActions)
                ->orWhereHas('actor', fn (Builder $actor) => $actor->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$pattern]))
                ->orWhereHasMorph('subject', [User::class, Group::class, Character::class, Activity::class],
                    function (Builder $subject, string $type) use ($pattern) {
                        $column = $type === Activity::class ? 'title' : 'name';
                        $subject->whereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", [$pattern]);
                    });

            if ($siteWide) {
                foreach (['group' => Group::class, 'user' => User::class, 'character' => Character::class] as $type => $model) {
                    $searchQuery->orWhere(fn (Builder $scope) => $scope->where('scope_type', $type)
                        ->whereIn('scope_id', $model::query()->select('id')->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$pattern])));
                }
                $searchQuery->orWhereHasMorph('subject', [ActivityType::class, BozjaItem::class, CharacterClass::class,
                    CharacterFieldDefinition::class, IntegrationClient::class, PhantomJob::class, RaidPosition::class, SurveyForm::class],
                    function (Builder $subject, string $type) use ($pattern) {
                        $column = match ($type) {
                            ActivityType::class => 'draft_name',
                            SurveyForm::class => 'slug',
                            default => 'name',
                        };
                        $subject->whereRaw("LOWER(CAST({$column} AS TEXT)) LIKE ? ESCAPE '\\'", [$pattern]);
                    });
            }

            if (str_contains(mb_strtolower(__('audit_log.defaults.system')), $needle)) {
                $searchQuery->orWhereNull('actor_user_id');
            }
        });
    }
}
