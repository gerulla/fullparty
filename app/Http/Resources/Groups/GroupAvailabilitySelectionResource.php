<?php

namespace App\Http\Resources\Groups;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupAvailabilitySelectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
