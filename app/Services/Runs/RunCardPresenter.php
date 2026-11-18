<?php

namespace App\Services\Runs;

use App\Models\Activity;
use App\Models\ActivityApplication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

final class RunCardPresenter
{
    public function __construct(private readonly RunParticipationService $participation) {}

    /** @param Collection<int, Activity> $activities */
    public function cards(Collection $activities, int $userId): BaseCollection
    {
        $activities->loadExists(RunBookmarkState::existsRelations($userId));
        $activities->loadCount(['applications' => fn (Builder $query) => $query->whereIn('status', ActivityApplication::ACTIVE_STATUSES)]);
        $activities->load([
            'group:id,name,slug,profile_picture_url',
            'activityTypeVersion',
            'organizerCharacter:id,name',
            'applications' => fn ($query) => $query->select('id', 'activity_id', 'user_id', 'applicant_avatar_url')
                ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)->orderBy('id')->limit(3),
            'applications.user:id,public_profile,public_characters',
        ]);
        $statuses = $this->participation->statusesFor($activities, $userId);

        return $activities->mapWithKeys(fn (Activity $activity) => [
            $activity->id => $this->card($activity, $statuses[$activity->id] ?? null, $userId),
        ]);
    }

    /** @return array<string, mixed> */
    private function card(Activity $activity, ?string $participation, int $userId): array
    {
        $version = $activity->activityTypeVersion;
        $progress = $activity->targetProgPoint();

        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'status' => $activity->status,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'duration_hours' => $activity->duration_hours,
            'href' => route('groups.activities.overview', ['locale' => app()->getLocale(),
                'group' => $activity->group->slug, 'activity' => $activity->id], false),
            'is_public' => (bool) $activity->is_public,
            ...RunBookmarkState::forVisibleRun($activity, $userId),
            'participation_status' => $participation,
            'host_name' => $activity->organizerCharacter?->name,
            'image_urls' => $activity->cardImageUrls(),
            'target_prog_point_key' => $activity->target_prog_point_key,
            'target_prog_point_label' => $progress['label'] ?? null,
            'application_count' => (int) $activity->applications_count,
            'applicant_avatars' => $activity->applications->map(fn (ActivityApplication $application) => $application->user?->public_profile && $application->user?->public_characters
                    ? $application->applicant_avatar_url : null)->all(),
            'group' => ['name' => $activity->group->name, 'profile_picture_url' => $activity->group->profile_picture_url],
            'activity_type' => [
                'name' => $version?->name,
                'small_image_url' => $version?->small_image_url,
                'banner_image_url' => $activity->bannerImageUrl(),
            ],
        ];
    }
}
