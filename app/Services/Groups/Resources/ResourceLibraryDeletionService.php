<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceImage;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResourceLibraryDeletionService
{
    public function __construct(
        private readonly ResourceLibraryService $libraries,
        private readonly GroupResourcePolicy $policy,
        private readonly ResourceAudit $audit,
        private readonly ResourceWorkflowService $workflow,
        private readonly ResourceImageReferences $references,
    ) {}

    public function deleteResource(Group $group, GroupResource $resource, User $user, array $data): void
    {
        abort_unless((int) $resource->group_id === (int) $group->id, 404);
        DB::transaction(function () use ($group, $resource, $user, $data) {
            $library = $this->libraries->lock($group);
            $resource = GroupResource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->policy->manage($user, $resource), 403);
            abort_if($resource->is_home, 422, __('resource_errors.home_protected'));
            abort_unless((int) $data['version'] === $resource->version, 409, __('resource_errors.stale'));
            if ($resource->editing_token_hash && $resource->editing_expires_at?->isFuture()) {
                $this->workflow->assertLease($resource, $user, $data);
            }
            $otherEditor = GroupResource::where('group_id', $group->id)->whereKeyNot($resource->id)->where('editing_expires_at', '>', now())->exists();
            $images = $resource->images()->get()->reject(function ($image) use ($resource, $otherEditor, $library) {
                if ($otherEditor || $this->references->branding($image, $library) || $this->references->resources($image)->whereKeyNot($resource->id)->exists()) {
                    $image->update(['resource_id' => null, 'library_upload' => true]);

                    return true;
                }

                return false;
            });
            GroupResourceImage::whereKey($images->modelKeys())->delete();
            $this->audit->record($group, $user, $resource, 'deleted');
            $resource->delete();
            $customization = $library->customization ?? [];
            if (($customization['start_resource_id'] ?? null) == $resource->id) {
                unset($customization['start_resource_id']);
            }
            $library->update([
                'customization' => $customization,
                'storage_used_bytes' => max(0, $library->storage_used_bytes - $images->sum('size_bytes')),
            ]);
            DB::afterCommit(function () use ($images) {
                try {
                    Storage::disk(config('group_resources.disk'))->delete($images->pluck('path')->all());
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        });
    }

    public function deleteResources(Group $group, User $user): int
    {
        abort_unless($this->policy->configure($user, $group), 403);

        return DB::transaction(function () use ($group, $user) {
            $library = $this->libraries->lock($group);
            // Keep uploads reusable and protected from abandoned-upload cleanup after their resources are removed.
            GroupResourceImage::where('group_id', $group->id)->update(['resource_id' => null, 'library_upload' => true]);
            $deleted = GroupResource::where('group_id', $group->id)->where('is_home', false)->delete();
            GroupResourceCollection::where('group_id', $group->id)->delete();
            $customization = $library->customization ?? [];
            unset($customization['start_resource_id']);
            $library->update(['customization' => $customization]);
            $this->audit->record($group, $user, $library, 'all_deleted');

            return $deleted;
        });
    }
}
