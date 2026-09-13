<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ResourceImageLibraryService
{
    public function __construct(private readonly GroupResourcePolicy $policy, private readonly ResourceLibraryService $libraries, private readonly ResourceImageReferences $references, private readonly ResourceAudit $audit) {}

    public function listing(Group $group, User $user, array $filters): LengthAwarePaginator
    {
        abort_unless($this->policy->manageLibrary($user, $group), 403);
        if (isset($filters['resource_id'])) {
            $resource = GroupResource::where('group_id', $group->id)->findOrFail($filters['resource_id']);
            abort_unless($this->policy->manage($user, $resource), 403);
        }
        $query = $this->policy->manageableImages($user, $group)->with('uploader:id,name', 'resource:id,editing_expires_at');
        if ($filters['library_only'] ?? false) {
            $query->whereNull('resource_id');
        }
        if ($search = trim($filters['q'] ?? '')) {
            $query->where(fn ($query) => $query->whereLike('original_name', '%'.$search.'%')->orWhereLike('alt_text', '%'.$search.'%')->orWhereLike('caption', '%'.$search.'%'));
        }
        if (($filters['type'] ?? 'all') === 'gif') {
            $query->where('mime_type', 'image/gif');
        } elseif (($filters['type'] ?? 'all') === 'image') {
            $query->where('mime_type', '!=', 'image/gif');
        }
        $library = GroupResourceLibrary::where('group_id', $group->id)->firstOrFail();

        return $query->orderByDesc('id')->paginate($filters['per_page'] ?? 24)->through(fn ($image) => $this->present($image, $library));
    }

    public function present(GroupResourceImage $image, ?GroupResourceLibrary $library = null): array
    {
        $library ??= GroupResourceLibrary::where('group_id', $image->group_id)->firstOrFail();

        return [
            'id' => $image->id,
            'uuid' => $image->uuid, 'name' => $image->original_name ?: $image->uuid.'.'.pathinfo($image->path, PATHINFO_EXTENSION),
            'url' => '/resource-assets/'.$image->uuid, 'mime_type' => $image->mime_type,
            'width' => $image->width, 'height' => $image->height, 'size_bytes' => $image->size_bytes,
            'alt_text' => $image->alt_text, 'caption' => $image->caption, 'created_at' => $image->created_at->toISOString(),
            'uploader' => $image->uploader?->name, 'in_use' => (bool) $this->references->inUse($image, $library),
        ];
    }

    public function update(Group $group, GroupResourceImage $image, User $user, array $data): array
    {
        return DB::transaction(function () use ($group, $image, $user, $data) {
            $library = $this->libraries->lock($group);
            $this->authorize($group, $image, $user);
            $image->update(['original_name' => $data['name'], 'alt_text' => $data['alt_text'] ?? '', 'caption' => $data['caption'] ?? null]);
            $this->audit->record($group, $user, $image, 'image_updated');

            return $this->present($image, $library);
        });
    }

    public function delete(Group $group, GroupResourceImage $image, User $user): void
    {
        DB::transaction(function () use ($group, $image, $user) {
            $library = $this->libraries->lock($group);
            $this->authorize($group, $image, $user);
            if ($this->references->inUse($image, $library)) {
                throw ValidationException::withMessages(['image' => __('resource_errors.image_in_use')]);
            }
            // A shared image may be selected in an editor that has not saved its snapshot yet.
            if (GroupResource::where('group_id', $group->id)->where('editing_expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['image' => __('resource_errors.image_editing')]);
            }
            $this->audit->record($group, $user, $image, 'image_deleted');
            $image->delete();
            $library->update(['storage_used_bytes' => max(0, $library->storage_used_bytes - $image->size_bytes)]);
            DB::afterCommit(function () use ($image) {
                try {
                    Storage::disk(config('group_resources.disk'))->delete($image->path);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        });
    }

    private function authorize(Group $group, GroupResourceImage $image, User $user): void
    {
        abort_unless((int) $image->group_id === (int) $group->id, 404);
        abort_unless($this->policy->manageableImages($user, $group)->whereKey($image->id)->exists(), 403);
    }
}
