<?php

namespace App\Services\Planner;

use App\Models\RaidPlan;
use App\Models\RaidPlanAccessLink;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RaidPlanService
{
    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     fight_id?: int|null,
     *     visibility?: string
     * }  $attributes
     */
    public function create(?User $author, array $attributes): RaidPlan
    {
        return DB::transaction(function () use ($author, $attributes): RaidPlan {
            $raidPlan = RaidPlan::query()->create([
                'author_id' => $author?->id,
                'activity_type_id' => $attributes['fight_id'] ?? null,
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'visibility' => $attributes['visibility'] ?? RaidPlan::VISIBILITY_UNLISTED,
            ]);

            foreach (RaidPlanAccessLink::PERMISSIONS as $permission) {
                $this->createAccessLink($raidPlan, $permission);
            }

            return $raidPlan->load(['author', 'fight.currentPublishedVersion', 'accessLinks']);
        });
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     fight_id?: int|null,
     *     visibility?: string
     * }  $attributes
     */
    public function update(RaidPlan $raidPlan, array $attributes): RaidPlan
    {
        $raidPlan->update([
            'activity_type_id' => $attributes['fight_id'] ?? null,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'visibility' => $attributes['visibility'] ?? RaidPlan::VISIBILITY_UNLISTED,
        ]);

        return $raidPlan->load(['author', 'fight.currentPublishedVersion', 'accessLinks']);
    }

    public function resolveByToken(string $token, string $permission): RaidPlan
    {
        $accessLink = RaidPlanAccessLink::query()
            ->where('permission', $permission)
            ->where('token_hash', $this->hashToken($token))
            ->with([
                'raidPlan.author',
                'raidPlan.fight.currentPublishedVersion',
                'raidPlan.accessLinks',
            ])
            ->first();

        if (! $accessLink?->raidPlan) {
            throw (new ModelNotFoundException)->setModel(RaidPlan::class);
        }

        return $accessLink->raidPlan;
    }

    private function createAccessLink(RaidPlan $raidPlan, string $permission): RaidPlanAccessLink
    {
        do {
            $token = Str::random(48);
            $tokenHash = $this->hashToken($token);
        } while (RaidPlanAccessLink::query()->where('token_hash', $tokenHash)->exists());

        return $raidPlan->accessLinks()->create([
            'permission' => $permission,
            'token' => $token,
            'token_hash' => $tokenHash,
        ]);
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
