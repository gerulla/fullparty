<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResourceImageService
{
    public function __construct(private readonly ResourceLibraryService $libraries, private readonly GroupResourcePolicy $policy, private readonly ResourceWorkflowService $workflow, private readonly ResourceAudit $audit, private readonly ResourceImageReferences $references) {}

    public function upload(Group $group, ?GroupResource $resource, User $user, UploadedFile $file, array $data): GroupResourceImage
    {
        abort_if($resource && (int) $resource->group_id !== (int) $group->id, 404);
        $libraryUpload = ! $resource && ($data['library_upload'] ?? false);
        abort_unless($resource ? $this->policy->manage($user, $resource) : ($libraryUpload ? $this->policy->manageLibrary($user, $group) : $this->policy->configure($user, $group)), 403);
        $info = @getimagesize($file->getRealPath());
        $mime = $info['mime'] ?? null;
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
        if (! $extension || $info[0] > 4096 || $info[1] > 4096 || $file->getSize() > config('group_resources.image_max_bytes')) {
            throw ValidationException::withMessages(['image' => __('resource_errors.image_invalid')]);
        }
        $decoded = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $decoded) {
            throw ValidationException::withMessages(['image' => __('resource_errors.image_invalid')]);
        }
        imagedestroy($decoded);
        $uuid = (string) Str::uuid();
        $path = "group-resources/{$group->id}/{$uuid}.{$extension}";
        try {
            return DB::transaction(function () use ($group, $resource, $user, $file, $data, $info, $mime, $uuid, $path, $libraryUpload) {
                $library = $this->libraries->lock($group);
                if ($resource) {
                    $resource->refresh();
                    abort_unless($this->policy->manage($user, $resource), 403);
                    abort_if((int) ($data['version'] ?? 0) !== $resource->version, 409, __('resource_errors.stale'));
                    $this->workflow->assertLease($resource, $user, $data);
                }
                if ($library->storage_used_bytes + $file->getSize() > config('group_resources.quota_bytes')) {
                    throw ValidationException::withMessages(['image' => __('resource_errors.quota')]);
                }
                $stored = Storage::disk(config('group_resources.disk'))->putFileAs(dirname($path), $file, basename($path));
                abort_unless($stored, 503);
                $image = GroupResourceImage::create([
                    'uuid' => $uuid, 'group_id' => $group->id, 'resource_id' => $resource?->id, 'uploader_user_id' => $user->id,
                    'access_level' => $resource?->management_access_level ?? ($libraryUpload ? 'everyone' : 'admin'),
                    'original_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 255, ''),
                    'library_upload' => $libraryUpload,
                    'path' => $path, 'mime_type' => $mime, 'width' => $info[0], 'height' => $info[1], 'size_bytes' => $file->getSize(),
                    'alt_text' => $data['alt_text'] ?? '', 'caption' => $data['caption'] ?? null,
                ]);
                $library->increment('storage_used_bytes', $image->size_bytes);
                $this->audit->record($group, $user, $image, 'image_uploaded');

                return $image;
            });
        } catch (\Throwable $exception) {
            Storage::disk(config('group_resources.disk'))->delete($path);
            throw $exception;
        }
    }

    public function canRead(GroupResourceImage $image, ?User $user, bool $public): bool
    {
        $group = Group::findOrFail($image->group_id);
        if (! $group->featureEnabled('resource_hub_enabled')) {
            return false;
        }
        $library = GroupResourceLibrary::where('group_id', $group->id)->first();
        if ($library && $this->references->branding($image, $library)
            && ($public ? $this->libraries->isPublic($group) : $this->policy->library($user, $group))) {
            return true;
        }
        if (! $public && $user && $this->policy->manageableImages($user, $group)->whereKey($image->id)->exists()) {
            return true;
        }
        $resources = GroupResource::where('group_id', $group->id)->withAvailableSource()->where('status', 'published')
            ->whereHas('publishedRevision', fn ($query) => $query->whereJsonContains('snapshot->image_ids', $image->uuid))->get();

        return $resources->contains(fn ($resource) => $public ? $this->libraries->publicUrl($resource) !== null : ($user && $this->policy->view($user, $resource)));
    }

    public function response(GroupResourceImage $image): StreamedResponse
    {
        abort_unless(Storage::disk(config('group_resources.disk'))->exists($image->path), 404);

        return Storage::disk(config('group_resources.disk'))->response($image->path, $image->uuid.'.'.pathinfo($image->path, PATHINFO_EXTENSION), [
            'Content-Type' => $image->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function cleanup(): int
    {
        $removed = 0;
        GroupResourceImage::where('created_at', '<', now()->subHours(config('group_resources.abandoned_upload_hours')))->chunkById(100, function ($images) use (&$removed) {
            foreach ($images as $candidate) {
                $removed += DB::transaction(function () use ($candidate) {
                    $group = Group::find($candidate->group_id);
                    if (! $group) {
                        return 0;
                    }
                    $library = $this->libraries->lock($group);
                    $image = GroupResourceImage::find($candidate->id);
                    if (! $image || $image->library_upload || $this->references->inUse($image, $library)
                        || GroupResource::where('group_id', $group->id)->where('editing_expires_at', '>', now())->exists()) {
                        return 0;
                    }
                    if (! Storage::disk(config('group_resources.disk'))->delete($image->path)) {
                        return 0;
                    }
                    $library->update(['storage_used_bytes' => max(0, $library->storage_used_bytes - $image->size_bytes)]);
                    $image->delete();

                    return 1;
                });
            }
        });

        $disk = Storage::disk(config('group_resources.disk'));
        foreach ($disk->directories('group-resources') as $directory) {
            $groupId = basename($directory);
            if (! ctype_digit($groupId)) {
                continue;
            }
            $group = Group::find($groupId);
            if (! $group) {
                $disk->deleteDirectory($directory);

                continue;
            }
            // A process can stop after writing the file but before committing its image record.
            DB::transaction(function () use ($group, $directory, $disk, &$removed) {
                $this->libraries->lock($group);
                $paths = array_flip(GroupResourceImage::where('group_id', $group->id)->pluck('path')->all());
                $cutoff = now()->subHours(config('group_resources.abandoned_upload_hours'))->timestamp;
                foreach ($disk->files($directory) as $path) {
                    if (! isset($paths[$path]) && $disk->lastModified($path) < $cutoff && $disk->delete($path)) {
                        $removed++;
                    }
                }
            });
        }

        return $removed;
    }
}
