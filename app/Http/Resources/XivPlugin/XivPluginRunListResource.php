<?php

namespace App\Http\Resources\XivPlugin;

use App\Models\Activity;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class XivPluginRunListResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        $resource,
        private readonly bool $canModerate,
        private readonly ?Group $group = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'group' => $this->group ? [
                'id' => $this->group->id,
                'slug' => $this->group->slug,
                'name' => $this->group->name,
                'can_moderate' => $this->canModerate,
            ] : null,
            'data' => $this->resource
                ->map(fn (Activity $activity): array => (new XivPluginRunResource($activity, $this->canModerate))->toArray($request))
                ->values()
                ->all(),
        ];
    }
}
