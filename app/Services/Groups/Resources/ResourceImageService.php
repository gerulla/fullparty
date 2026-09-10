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
    public function __construct(private readonly ResourceLibraryService $libraries, private readonly GroupResourcePolicy $policy, private readonly ResourceWorkflowService $workflow, private readonly ResourceAudit $audit) {}

    public function upload(Group $group, ?GroupResource $resource, User $user, UploadedFile $file, array $data): GroupResourceImage
    {
        abort_if($resource && (int) $resource->group_id !== (int) $group->id, 404);
        abort_unless($resource ? $this->policy->manage($user, $resource) : $this->policy->configure($user, $group), 403);
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
            return DB::transaction(function () use ($group, $resource, $user, $file, $data, $info, $mime, $uuid, $path) {
                $library = $this->libraries->lock($group);
                if ($resource) {
                    $resource->refresh();
                    abort_unless($this->policy->manage($user, $resource), 403);
                    abort_if($resource->pending_revision_id || (int) ($data['version'] ?? 0) !== $resource->version, 409, __('resource_errors.pending'));
                    $this->workflow->assertLease($resource, $user, $data);
                }
                if ($library->storage_used_bytes + $file->getSize() > config('group_resources.quota_bytes')) {
                    throw ValidationException::withMessages(['image' => __('resource_errors.quota')]);
                }
                $stored = Storage::disk(config('group_resources.disk'))->putFileAs(dirname($path), $file, basename($path));
                abort_unless($stored, 503);
                $image = GroupResourceImage::create([
                    'uuid' => $uuid, 'group_id' => $group->id, 'resource_id' => $resource?->id, 'uploader_user_id' => $user->id,
                    'access_level' => $resource?->management_access_level ?? 'admin',
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
        if (! $image->resource_id) {
            $branding = GroupResourceLibrary::where('group_id', $group->id)->value('customization');
            $branding = is_string($branding) ? json_decode($branding, true) : $branding;
            $referenced = in_array($image->uuid, array_intersect_key($branding ?? [], array_flip(['banner_image_id', 'logo_image_id', 'sharing_image_id'])), true);

            return $public ? $referenced && $this->libraries->isPublic($group) : ($user && $this->policy->configure($user, $group)) || ($referenced && $this->policy->library($user, $group));
        }
        $resource = $image->resource;
        $live = in_array($image->uuid, $resource->publishedRevision?->snapshot['image_ids'] ?? [], true);
        if ($public) {
            return $live && $this->libraries->publicUrl($resource) !== null;
        }
        if (! $user) {
            return false;
        }
        if ($this->policy->view($user, $resource) && $live) {
            return true;
        }

        return $this->policy->useImage($user, $image, $resource);
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
                    if (! $image || $this->referenced($image, $library)) {
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

    private function referenced(GroupResourceImage $image, GroupResourceLibrary $library): bool
    {
        if (! $image->resource_id) {
            return in_array($image->uuid, array_intersect_key($library->customization ?? [], array_flip(['banner_image_id', 'logo_image_id', 'sharing_image_id'])), true);
        }
        $resource = $image->resource;
        if ($resource->editing_expires_at?->isFuture() || in_array($image->uuid, $resource->working_copy['image_ids'] ?? [], true)) {
            return true;
        }

        return $resource->revisions()->get(['snapshot'])->contains(fn ($revision) => in_array($image->uuid, $revision->snapshot['image_ids'] ?? [], true));
    }
}
