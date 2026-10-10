<?php

namespace App\Services\Notifications;

use App\Models\ActivityPartyFinderInfo;
use App\Models\Character;

class PartyFinderNotificationPayloadBuilder
{
    /** @return array<string, mixed> */
    public function build(ActivityPartyFinderInfo $info): array
    {
        $datacenter = collect(config('datacenters.worlds', []))->search(
            fn (array $worlds) => collect($worlds)->contains(fn (string $world) => strcasecmp($world, trim($info->world)) === 0),
        );
        // The listing world can differ from the host's home world. Only use an
        // unambiguous verified character owned by the publisher, never a global name match.
        $characters = $info->published_by_user_id
            ? Character::query()->where('user_id', $info->published_by_user_id)->whereNotNull('verified_at')
                ->get(['name', 'world'])
                ->filter(fn (Character $character) => strcasecmp($character->name, trim($info->character_name)) === 0)
            : collect();

        return [
            'character_name' => $info->character_name,
            'world' => $info->world,
            'datacenter' => $datacenter !== false ? $datacenter : null,
            'region' => $datacenter !== false ? config('datacenters.regions.'.$datacenter) : null,
            'character_world' => $characters->count() === 1 ? $characters->first()->world : null,
            'password' => $info->password,
            'published_at' => $info->published_at?->toIso8601String(),
        ];
    }
}
