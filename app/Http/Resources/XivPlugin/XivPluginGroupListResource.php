<?php

namespace App\Http\Resources\XivPlugin;

use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class XivPluginGroupListResource extends JsonResource
{
    public static $wrap = null;

    public function __construct($resource, private readonly User $viewer)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->resource
                ->map(fn (Group $group): array => (new XivPluginGroupResource($group, $this->viewer))->toArray($request))
                ->values()
                ->all(),
        ];
    }
}
