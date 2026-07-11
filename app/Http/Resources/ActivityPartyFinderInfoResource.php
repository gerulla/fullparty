<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityPartyFinderInfoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'character_name' => $this->character_name,
            'world' => $this->world,
            'password' => $this->password,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
