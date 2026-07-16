<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BozjaHolsterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->localizedName(),
            'role' => $this->role,
            'type' => $this->type,
            'parent_holster_id' => $this->parent_holster_id,
            'max_capacity' => $this->max_capacity,
            'capacity_used' => $this->capacity_used,
            'notes' => $this->notes,
            'guide' => $this->guide,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'items' => $this->whenLoaded('items', fn () => $this->items
                ->map(fn ($item) => [
                    ...(new BozjaItemOptionResource($item))->resolve($request),
                    'quantity' => (int) $item->pivot->quantity,
                ])
                ->values()),
        ];
    }
}
