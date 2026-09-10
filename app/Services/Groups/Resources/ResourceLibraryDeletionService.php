<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
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
    ) {}

    public function deleteResources(Group $group, User $user): int
    {
        abort_unless($this->policy->configure($user, $group), 403);

        return DB::transaction(function () use ($group, $user) {
            $library = $this->libraries->lock($group);
            $images = GroupResourceImage::where('group_id', $group->id)->whereNotNull('resource_id')->get(['id', 'path', 'size_bytes']);
            GroupResourceImage::where('group_id', $group->id)->whereNotNull('resource_id')->delete();
            $deleted = GroupResource::where('group_id', $group->id)->delete();
            $customization = $library->customization ?? [];
            unset($customization['start_resource_id']);
            $library->update([
                'customization' => $customization,
                'storage_used_bytes' => max(0, $library->storage_used_bytes - $images->sum('size_bytes')),
            ]);
            $this->audit->record($group, $user, $library, 'all_deleted');

            // Never remove files before the database commits. Orphan cleanup retries failed storage deletes.
            DB::afterCommit(function () use ($images) {
                try {
                    foreach ($images->pluck('path')->chunk(100) as $paths) {
                        Storage::disk(config('group_resources.disk'))->delete($paths->all());
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            return $deleted;
        });
    }
}
