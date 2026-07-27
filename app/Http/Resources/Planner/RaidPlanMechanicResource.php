<?php

namespace App\Http\Resources\Planner;

use App\Models\RaidPlanMechanic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RaidPlanMechanicResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RaidPlanMechanic $mechanic */
        $mechanic = $this->resource;

        return [
            'id' => $mechanic->id,
            'name' => $mechanic->name,
            'type' => $mechanic->type,
            'sort_order' => $mechanic->sort_order,
            'duration_ms' => $mechanic->duration_ms,
            'selection_weight' => $mechanic->selection_weight,
            'is_enabled' => $mechanic->is_enabled,
            'timeline' => $mechanic->timeline,
            'timeline_schema_version' => $mechanic->timeline_schema_version,
            'variants' => $mechanic->parent_id === null
                ? self::collection($mechanic->children)->resolve($request)
                : [],
        ];
    }
}
