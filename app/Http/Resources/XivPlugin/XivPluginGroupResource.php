<?php

namespace App\Http\Resources\XivPlugin;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class XivPluginGroupResource extends JsonResource
{
    public function __construct($resource, private readonly ?User $viewer = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Group $group */
        $group = $this->resource;
        $viewer = $this->viewer ?? $request->user();
        $role = $group->owner_id === $viewer?->id
            ? GroupMembership::ROLE_OWNER
            : $group->memberships->first()?->role;

        return [
            'id' => $group->id,
            'slug' => $group->slug,
            'name' => $group->name,
            'profile_picture_url' => $group->profile_picture_url,
            'banner_image_url' => $group->banner_image_url,
            'datacenter' => $group->datacenter,
            'role' => $role,
            'can_moderate' => $group->hasModeratorAccess($viewer?->id),
        ];
    }
}
