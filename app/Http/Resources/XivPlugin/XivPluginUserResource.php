<?php

namespace App\Http\Resources\XivPlugin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class XivPluginUserResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $character = $user->primaryCharacter;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'primary_character' => $character ? [
                    'id' => $character->id,
                    'name' => $character->name,
                    'world' => $character->world,
                    'datacenter' => $character->datacenter,
                    'avatar_url' => $character->avatar_url,
                ] : null,
            ],
        ];
    }
}
