<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class GroupActivityRosterDiscordController extends Controller
{
    public function show(Group $group, Activity $activity): JsonResponse
    {
        $this->authorize('manageDashboard', [$activity, $group]);

        $userIds = Character::query()->select('user_id')->whereNotNull('user_id')
            ->whereIn('id', $activity->slots()->select('assigned_character_id'));
        $discordIds = User::query()->whereIn('id', $userIds)->with([
            'discordUserIntegration:id,user_id,discord_user_id',
            'socialAccounts' => fn ($query) => $query->where('provider', 'discord')->select(['id', 'user_id', 'provider_user_id']),
        ])->get(['id'])->mapWithKeys(function (User $user): array {
            $id = $user->discordUserIntegration?->discord_user_id
                ?: $user->socialAccounts->first()?->provider_user_id;

            return [$user->id => $id ? (string) $id : null];
        })->filter(fn ($id) => is_string($id) && preg_match('/^[0-9]{1,32}$/D', $id));

        return response()->json(['discord_user_ids' => (object) $discordIds->all()])
            ->header('Cache-Control', 'private, no-store');
    }
}
