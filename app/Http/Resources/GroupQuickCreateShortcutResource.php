<?php

namespace App\Http\Resources;

use App\Models\GroupQuickCreateShortcut;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GroupQuickCreateShortcut */
class GroupQuickCreateShortcutResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->exists ? (int) $this->id : null,
            'time' => $this->time_of_day,
            'time_mode' => $this->time_mode,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
