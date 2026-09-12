<?php

namespace App\Services\Groups\Resources;

use App\Models\ActivityType;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceImage;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use App\Services\RichText\RichTextDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResourceStarterLibraryService
{
    public function __construct(
        private readonly ResourceHomeService $homes,
        private readonly ResourceCollectionService $collections,
        private readonly ResourceWorkflowService $workflow,
        private readonly ResourceImageService $images,
        private readonly ResourceStarterContent $content,
        private readonly GroupResourcePolicy $policy,
    ) {}

    public function initialize(Group $group, User $user): bool
    {
        $image = null;
        try {
            return DB::transaction(function () use ($group, $user, &$image) {
                Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
                $group->load('features');
                if (! $group->features->resource_hub_enabled || $group->features->resource_hub_initialized_at) {
                    return false;
                }
                abort_unless($this->policy->configure($user, $group), 403);
                $home = $this->homes->ensure($group);
                if ($this->hasContent($group, $home)) {
                    $this->markInitialized($group);

                    return false;
                }

                $folders = [];
                foreach ($this->content->collections() as $key => $folder) {
                    $folders[$key] = $this->collections->save($group, $user, [
                        'name' => $folder['name'], 'slug' => 'starter-'.$key,
                        'parent_id' => isset($folder['parent']) ? $folders[$folder['parent']]->id : null,
                        'icon' => $folder['icon'],
                    ]);
                }
                $image = $this->images->upload($group, null, $user, new UploadedFile(
                    public_path('resource-sample-planning-map.jpg'), 'starter-planning-map.jpg', 'image/jpeg', test: true,
                ), ['library_upload' => true, 'alt_text' => __('resource_starter.image_alt'), 'caption' => __('resource_starter.image_caption')]);
                $activities = ActivityType::where('is_active', true)->whereNotNull('current_published_version_id')->orderBy('id')->limit(2)->pluck('id')->all();

                foreach ($this->content->resources($image->uuid, $activities) as $definition) {
                    $resource = $this->workflow->create($group, $user, [
                        'collection_id' => isset($definition['collection']) ? $folders[$definition['collection']]->id : null,
                        'content' => array_replace($definition['content'], ['body' => RichTextDocument::empty(), 'metadata_image_id' => null, 'commands' => []]),
                    ]);
                    $this->save($group, $resource, $user, $definition['content'], $definition['state']);
                }
                $this->save($group, $home, $user, $this->content->home($group, $home->slug), 'published');
                $this->markInitialized($group);

                return true;
            });
        } catch (\Throwable $exception) {
            if ($image) {
                Storage::disk(config('group_resources.disk'))->delete($image->path);
            }
            throw $exception;
        }
    }

    private function hasContent(Group $group, GroupResource $home): bool
    {
        $snapshot = $home->publishedRevision?->snapshot ?? [];

        return GroupResource::where('group_id', $group->id)->where('is_home', false)->exists()
            || GroupResourceCollection::where('group_id', $group->id)->exists()
            || GroupResourceImage::where('group_id', $group->id)->exists()
            || $home->working_copy !== null || $home->revisions()->count() > 1
            || ($snapshot['body'] ?? null) !== RichTextDocument::empty()
            || ($snapshot['title'] ?? null) !== 'Home' || ! empty($snapshot['description']);
    }

    private function save(Group $group, GroupResource $resource, User $user, array $content, string $state): void
    {
        $lease = $this->workflow->mutate($group, $resource, $user, 'acquire', ['version' => $resource->fresh()->version]);
        $saved = $this->workflow->mutate($group, $resource, $user, 'save', [
            'version' => $lease['version'], 'editing_token' => $lease['editing_token'],
            'content' => $content, 'summary' => __('resource_starter.revision_summary'),
        ]);
        if ($state !== 'draft') {
            $saved = $this->workflow->mutate($group, $resource, $user, 'publish', ['version' => $saved['version'], 'editing_token' => $lease['editing_token']]);
        }
        $released = $this->workflow->mutate($group, $resource, $user, 'release', ['version' => $saved['version'], 'editing_token' => $lease['editing_token']]);
        if ($state === 'archived') {
            $this->workflow->mutate($group, $resource, $user, 'archive', ['version' => $released['version']]);
        }
    }

    private function markInitialized(Group $group): void
    {
        $group->features->forceFill(['resource_hub_initialized_at' => now()])->save();
    }
}
