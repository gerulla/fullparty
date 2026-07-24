<?php

namespace App\Http\Resources\Planner;

use App\Models\RaidPlan;
use App\Models\RaidPlanAccessLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RaidPlanResource extends JsonResource
{
    public function __construct(
        RaidPlan $resource,
        private readonly bool $canEdit = false,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RaidPlan $raidPlan */
        $raidPlan = $this->resource;
        $viewToken = $raidPlan->accessToken(RaidPlanAccessLink::PERMISSION_VIEW);

        return [
            'id' => $raidPlan->id,
            'name' => $raidPlan->name,
            'description' => $raidPlan->description,
            'fight_id' => $raidPlan->activity_type_id,
            'visibility' => $raidPlan->visibility,
            'author' => $raidPlan->author ? [
                'id' => $raidPlan->author->id,
                'name' => $raidPlan->author->name,
                'avatar_url' => $raidPlan->author->avatar_url,
            ] : null,
            'is_saved_to_account' => $raidPlan->author_id !== null,
            'is_owned_by_current_user' => $raidPlan->author_id !== null
                && $raidPlan->author_id === $request->user()?->id,
            'can_edit' => $this->canEdit,
            'links' => [
                'view' => route('planner.view', ['token' => $viewToken]),
                'edit' => $this->when(
                    $this->canEdit,
                    fn () => route('planner.edit', [
                        'token' => $raidPlan->accessToken(
                            RaidPlanAccessLink::PERMISSION_EDIT,
                        ),
                    ])
                ),
            ],
            'created_at' => $raidPlan->created_at?->toIso8601String(),
            'updated_at' => $raidPlan->updated_at?->toIso8601String(),
        ];
    }
}
