<?php

namespace App\Http\Resources;

use App\Models\ActivityManagementWarning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ActivityManagementWarning */
class ActivityManagementWarningResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            'payload' => $this->payload,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
        ];
    }
}
