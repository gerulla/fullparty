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

        return GroupResourceLibrary::firstOrCreate(['group_id' => $group->id], ['customization' => $this->customization($group, [])]);
    }

    public function settings(Group $group, User $user, array $input): GroupResourceLibrary
    {
        abort_unless($this->policy->configure($user, $group), 403);

        return DB::transaction(function () use ($group, $user, $input) {
            $library = $this->lock($group);
            $customization = $this->customization($group, $library->customization ?? []);
            if (($input['visibility'] ?? null) === 'public' && is_array($input['customization'] ?? null)) {
                $input['customization'] += array_intersect_key($customization, array_flip(['title', 'introduction']));
            }
            $imageRule = Rule::exists('group_resource_images', 'uuid')->where('group_id', $group->id)->whereNull('resource_id');
            $data = Validator::make($input, [
                'visibility' => ['required', Rule::in(['public', 'private'])],
                'customization' => ['exclude_unless:visibility,public', 'sometimes', 'array:title,introduction,banner_image_id,banner_focal_x,banner_focal_y,logo_image_id,accent_color,appearance,start_resource_id,links,sharing_image_id'],
                'customization.title' => ['required_with:customization', 'string', 'max:160'],
                'customization.introduction' => ['required_with:customization', 'string', 'max:2000'],
                'customization.banner_image_id' => ['nullable', 'uuid', $imageRule],
                'customization.logo_image_id' => ['nullable', 'uuid', $imageRule],
                'customization.sharing_image_id' => ['nullable', 'uuid', $imageRule],
                'customization.banner_focal_x' => ['nullable', 'numeric', 'between:0,100'],
                'customization.banner_focal_y' => ['nullable', 'numeric', 'between:0,100'],
                'customization.accent_color' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/'],
                'customization.appearance' => ['nullable', Rule::in(['light', 'dark', 'system'])],
                'customization.start_resource_id' => ['integer', Rule::exists('group_resources', 'id')->where('group_id', $group->id)->where('is_home', true)],
                'customization.links' => ['nullable', 'array', 'max:8'],
                'customization.links.*' => ['array:label,url'],
                'customization.links.*.label' => ['required', 'string', 'max:60'],
                'customization.links.*.url' => ['required', 'url:http,https', 'max:2048'],
            ], [], __('resource_errors.library_fields'))->validate();
            $data['customization'] ??= $customization;
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

        return route('public-resources.show', ['group' => $resource->group->slug, 'slug' => $resource->uuid]);
    }

    public function payload(Group $group, bool $manage = false): array
    {
        $library = GroupResourceLibrary::where('group_id', $group->id)->first();
        $customization = $this->customization($group, $library?->customization ?? []);
        $customization['start_resource_id'] = GroupResource::where('group_id', $group->id)->where('is_home', true)->value('id');

        return ['visibility' => $library?->visibility ?? GroupResourceLibrary::DEFAULT_VISIBILITY, 'customization' => $customization] + ($manage ? [
            'public_url' => $this->isPublic($group) ? route('public-resources.index', ['group' => $group->slug]) : null,
            'storage' => ['used_bytes' => $library?->storage_used_bytes ?? 0, 'quota_bytes' => config('group_resources.quota_bytes')],
        ] : []);
    }

    private function customization(Group $group, array $customization): array
    {
        $titleTemplateLength = mb_strlen(__('resource_library.default_title', ['group' => '']));
        $defaults = [
            'title' => __('resource_library.default_title', ['group' => mb_substr($group->name, 0, max(1, 160 - $titleTemplateLength))]),
            'introduction' => __('resource_library.default_introduction', ['group' => $group->name]),
        ];
        foreach ($defaults as $key => $value) {
            if (! is_string($customization[$key] ?? null) || trim($customization[$key]) === '') {
                $customization[$key] = $value;
            }
        }

        return $customization;
    }
}
