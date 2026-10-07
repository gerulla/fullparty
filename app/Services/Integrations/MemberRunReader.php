<?php

namespace App\Services\Integrations;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\User;
use App\Support\Activities\ActivityDisplayName;
use Illuminate\Database\Eloquent\Builder;

final class MemberRunReader
{
    public function query(User $user): Builder
    {
        return Activity::query()->whereNotIn('status', Activity::MODERATOR_ONLY_STATUSES)
            ->whereHas('group', fn ($q) => $q
                ->whereDoesntHave('bans', fn ($bans) => $bans->where('user_id', $user->id))
                ->where(fn ($groups) => $groups->where('is_visible', true)
                    ->orWhereHas('memberships', fn ($members) => $members->where('user_id', $user->id))))
            ->with(['group:id,name,slug', 'activityTypeVersion', 'organizer:id,name,avatar_url']);
    }

    public function applications(User $user): Builder
    {
        // Keep the user's history, but never grant current run access through an old application.
        return ActivityApplication::where('user_id', $user->id)->with([
            'answers',
            'activity' => fn ($activity) => $activity
                ->whereIn('activities.id', $this->query($user)->withoutEagerLoads()->select('activities.id'))
                ->with(['group:id,name,slug', 'activityTypeVersion', 'organizer:id,name,avatar_url']),
        ]);
    }

    public function present(Activity $run): array
    {
        return [
            'id' => $run->id, 'title' => ActivityDisplayName::for($run),
            'description' => $run->description, 'notes' => $run->notes, 'status' => $run->status,
            'starts_at' => $run->starts_at?->toIso8601String(), 'duration_hours' => $run->duration_hours === null ? null : (float) $run->duration_hours,
            'datacenter' => $run->datacenter, 'is_public' => (bool) $run->is_public,
            'needs_application' => (bool) $run->needs_application, 'accepts_applications' => $run->acceptsApplications(),
            'group' => $run->group?->only(['id', 'name', 'slug']),
            'activity_type' => ['id' => $run->activity_type_id, 'version_id' => $run->activity_type_version_id,
                'name' => $run->activityTypeVersion?->name, 'difficulty' => $run->activityTypeVersion?->difficulty],
            'organizer' => $run->organizer?->only(['id', 'name', 'avatar_url']),
            'target_prog_point_key' => $run->target_prog_point_key, 'prog_points' => $run->activityTypeVersion?->prog_points ?? [],
            'min_item_level' => $run->min_item_level, 'run_style' => $run->run_style, 'intensity' => $run->intensity,
            'url' => route('groups.activities.overview', ['group' => $run->group, 'activity' => $run]),
        ];
    }

    public function application(ActivityApplication $application): array
    {
        return [
            ...$application->only(['id', 'activity_id', 'selected_character_id', 'status', 'notes']),
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'review_reason' => $application->localizedReviewReason(),
            'answers' => $application->answers->mapWithKeys(fn ($answer) => [$answer->question_key => $answer->value])->all(),
            'run' => $application->activity ? $this->present($application->activity) : null,
        ];
    }
}
