<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'avatar_url' => $this->avatar_url,
            'is_admin' => $this->is_admin, 'banned_at' => $this->banned_at?->toIso8601String(),
            'public_profile' => $this->public_profile, 'created_at' => $this->created_at?->toIso8601String(),
            'description' => $this->homeProfile?->description,
            'characters' => $this->characters->map(fn ($character) => $character->only([
                'id', 'name', 'world', 'datacenter', 'avatar_url', 'is_primary',
            ]))->values(),
        ];
    }
}
