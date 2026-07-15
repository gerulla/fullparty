<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BozjaItemOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'category' => $this->category,
            'name' => $this->name,
            'display_name' => $this->localizedName(),
            'description' => $this->description,
            'classification' => $this->classification,
            'cache_weight' => $this->cache_weight,
            'icon_url' => $this->icon_url,
        ];
    }
}
