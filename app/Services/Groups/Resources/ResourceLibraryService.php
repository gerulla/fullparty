<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ResourceLibraryService
{
    public function __construct(private readonly GroupResourcePolicy $policy, private readonly ResourceAudit $audit) {}

    /** All library mutations lock the group first, including quota and namespace changes. */
    public function lock(Group $group): GroupResourceLibrary
    {
        Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

        return GroupResourceLibrary::firstOrCreate(['group_id' => $group->id]);
    }

    public function settings(Group $group, User $user, array $input): GroupResourceLibrary
    {
        abort_unless($this->policy->configure($user, $group), 403);

        return DB::transaction(function () use ($group, $user, $input) {
            $library = $this->lock($group);
            $imageRule = Rule::exists('group_resource_images', 'uuid')->where('group_id', $group->id)->whereNull('resource_id');
            $data = Validator::make($input, [
                'visibility' => ['required', Rule::in(['public', 'private'])],
                'customization' => ['sometimes', 'array:title,introduction,banner_image_id,banner_focal_x,banner_focal_y,logo_image_id,accent_color,appearance,start_resource_id,links,sharing_image_id'],
                'customization.title' => ['nullable', 'string', 'max:160'],
                'customization.introduction' => ['nullable', 'string', 'max:2000'],
                'customization.banner_image_id' => ['nullable', 'uuid', $imageRule],
                'customization.logo_image_id' => ['nullable', 'uuid', $imageRule],
                'customization.sharing_image_id' => ['nullable', 'uuid', $imageRule],
                'customization.banner_focal_x' => ['nullable', 'numeric', 'between:0,100'],
                'customization.banner_focal_y' => ['nullable', 'numeric', 'between:0,100'],
                'customization.accent_color' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/'],
                'customization.appearance' => ['nullable', Rule::in(['light', 'dark', 'system'])],
                'customization.start_resource_id' => ['nullable', 'integer', Rule::exists('group_resources', 'id')->where('group_id', $group->id)->where('status', 'published')->where('access_level', 'everyone')],
                'customization.links' => ['nullable', 'array', 'max:8'],
                'customization.links.*' => ['array:label,url'],
                'customization.links.*.label' => ['required', 'string', 'max:60'],
                'customization.links.*.url' => ['required', 'url:http,https', 'max:2048'],
            ])->validate();
            $library->update($data);
            $this->audit->record($group, $user, $library, 'settings_updated');

            return $library;
        });
    }

    public function isPublic(Group $group): bool
    {
        return $group->featureEnabled('resource_hub_enabled')
            && (GroupResourceLibrary::where('group_id', $group->id)->value('visibility') ?? GroupResourceLibrary::DEFAULT_VISIBILITY) === 'public';
    }

    public function publicUrl(GroupResource $resource): ?string
    {
        if (! $this->isPublic($resource->group) || $resource->status !== 'published' || ! $resource->published_revision_id || $resource->access_level !== 'everyone') {
            return null;
        }

        return route('public-resources.show', ['group' => $resource->group->slug, 'slug' => $resource->slug]);
    }

    public function payload(Group $group, bool $manage = false): array
    {
        $library = GroupResourceLibrary::where('group_id', $group->id)->first();
        $customization = $library?->customization ?? [];
        if (isset($customization['start_resource_id']) && ! GroupResource::where('group_id', $group->id)->whereKey($customization['start_resource_id'])->where('status', 'published')->where('access_level', 'everyone')->exists()) {
            unset($customization['start_resource_id']);
        }

        return ['visibility' => $library?->visibility ?? GroupResourceLibrary::DEFAULT_VISIBILITY, 'customization' => $customization] + ($manage ? ['storage' => ['used_bytes' => $library?->storage_used_bytes ?? 0, 'quota_bytes' => config('group_resources.quota_bytes')]] : []);
    }
}
