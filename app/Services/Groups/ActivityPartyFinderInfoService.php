<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityPartyFinderInfo;
use App\Models\User;
use App\Services\Notifications\RunNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivityPartyFinderInfoService
{
    public function __construct(
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly RunNotificationService $runNotificationService,
    ) {}

    /**
     * @param  array{character_name: string, world: string, password: string}  $data
     */
    public function publish(Activity $activity, User $actor, array $data): ActivityPartyFinderInfo
    {
        if ($activity->isArchived()) {
            throw ValidationException::withMessages([
                'activity' => __('groups.activities.management.messages.party_finder_archived'),
            ]);
        }

        $existing = $activity->partyFinderInfo;
        $changes = [
            'party_finder_character_name' => [
                'old' => $existing?->character_name,
                'new' => $data['character_name'],
            ],
            'party_finder_world' => [
                'old' => $existing?->world,
                'new' => $data['world'],
            ],
            'party_finder_password' => [
                'old' => $existing ? '[redacted]' : null,
                'new' => '[redacted]',
            ],
        ];

        $info = DB::transaction(function () use ($activity, $actor, $data): ActivityPartyFinderInfo {
            $info = $activity->partyFinderInfo()->updateOrCreate([], [
                ...$data,
                'published_by_user_id' => $actor->id,
                'published_at' => now(),
            ]);

            $activity->touch();

            return $info;
        });

        $activity->setRelation('partyFinderInfo', $info);
        $this->activityAuditService->logActivityUpdated($activity, $actor, $changes);
        $this->runNotificationService->notifyPartyFinderPublished($activity, $info, $actor);

        return $info;
    }
}
