<?php

namespace App\Services\Groups\Resources;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use App\Models\GroupResourceRevision;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Support\Collection;

final class ResourceAuditContext
{
    public function __construct(private readonly GroupResourcePolicy $policy) {}

    /** Return only context the viewer may read, keyed by audit event ID. */
    public function forLogs(Collection $logs, Group $group, ?User $viewer): Collection
    {
        if (! $viewer || ! $this->policy->manageLibrary($viewer, $group)) {
            return collect();
        }
        $logs = $logs->filter(fn (AuditLog $log) => str_starts_with($log->action, 'group.resources.')
            && $log->scope_type === 'group' && (int) $log->scope_id === (int) $group->id && $log->subject_id);
        if ($logs->isEmpty()) {
            return collect();
        }
        $levels = $this->policy->levels($viewer, $group);
        $ids = fn (string $type) => $logs->where('subject_type', $type)->pluck('subject_id')->filter()->unique();
        $resourceIds = $ids(GroupResource::class);
        $resources = GroupResource::where('group_id', $group->id)->whereIn('id', $resourceIds)
            ->with(['publishedRevision:id,resource_id,snapshot->title as title', 'holster'])
            ->get(['id', 'uuid', 'group_id', 'is_home', 'holster_id', 'slug', 'status', 'access_level', 'management_access_level', 'published_revision_id', 'moderation_hidden_at', 'working_copy->title as draft_title'])
            ->keyBy('id');
        $availableResources = GroupResource::where('group_id', $group->id)->whereIn('id', $resourceIds)
            ->withAvailableSource(includeHidden: true)->pluck('id')->flip();
        $revisions = GroupResourceRevision::whereIn('resource_id', $resourceIds)
            ->whereIn('id', $logs->pluck('metadata.revision_id')->filter()->unique())
            ->get(['id', 'resource_id', 'summary', 'snapshot->access_level as access_level'])->keyBy('id');
        $collections = GroupResourceCollection::where('group_id', $group->id)->whereIn('id', $ids(GroupResourceCollection::class))->get()->keyBy('id');
        $visibleCollections = GroupResource::where('group_id', $group->id)->withAvailableSource()
            ->where('status', 'published')->whereNotNull('published_revision_id')->whereIn('access_level', $levels)
            ->whereIn('collection_id', $collections->keys())->pluck('collection_id')->flip();
        $imageIds = $ids(GroupResourceImage::class);
        $existingImages = $imageIds->isEmpty() ? collect() : GroupResourceImage::where('group_id', $group->id)->whereIn('id', $imageIds)->pluck('id')->flip();
        $images = $imageIds->isEmpty() ? collect() : $this->policy->manageableImages($viewer, $group)->whereIn('id', $imageIds)
            ->get(['id', 'group_id', 'uuid', 'original_name'])->keyBy('id');
        $libraries = GroupResourceLibrary::where('group_id', $group->id)->whereIn('id', $ids(GroupResourceLibrary::class))->get()->keyBy('id');
        $contexts = collect();

        foreach ($logs as $log) {
            $snapshot = ($log->metadata['resource_audit_version'] ?? null) === 1 ? $log->metadata : [];
            $context = match ($log->subject_type) {
                GroupResource::class => $this->resource($log, $snapshot, $resources->get($log->subject_id), $availableResources, $revisions, $group, $viewer, $levels),
                GroupResourceCollection::class => $this->collection($log, $snapshot, $collections->get($log->subject_id), $visibleCollections, $group),
                GroupResourceImage::class => $this->image($log, $snapshot, $images->get($log->subject_id), $existingImages->has($log->subject_id), $viewer, $levels),
                GroupResourceLibrary::class => in_array('admin', $levels, true) && $libraries->has($log->subject_id)
                    ? $this->context('library', $log->subject_id, $snapshot['library_title'] ?? $libraries->get($log->subject_id)->customization['title'] ?? $group->name, route('groups.dashboard.resources.manage', ['group' => $group])) : null,
                default => null,
            };
            if ($context) {
                $contexts->put($log->id, $context);
            }
        }

        return $contexts;
    }

    private function resource(AuditLog $log, array $snapshot, ?GroupResource $resource, Collection $availableResources, Collection $revisions, Group $group, User $viewer, array $levels): ?array
    {
        if ($snapshot !== [] && (! in_array($snapshot['resource_access_level'] ?? null, $levels, true)
            || ! in_array($snapshot['resource_management_access_level'] ?? null, $levels, true))) {
            return null;
        }
        if ($resource) {
            $resource->setRelation('group', $group);
            if (! $this->policy->manage($viewer, $resource) || ! $availableResources->has($resource->id)) {
                return null;
            }
        } elseif ($snapshot === []) {
            return null;
        }

        $revision = $revisions->get($snapshot['revision_id'] ?? null);
        if ($revision && ((int) $revision->resource_id !== (int) $log->subject_id || ! in_array($revision->access_level, $levels, true))) {
            return null;
        }
        $title = $snapshot['resource_title'] ?? $resource?->draft_title ?? $resource?->publishedRevision?->title ?? $resource?->slug;
        if (! is_string($title) || $title === '') {
            return null;
        }
        $url = null;
        if ($resource) {
            $url = $this->policy->view($viewer, $resource) && ! $resource->holster?->moderation_hidden_at
                ? route($resource->is_home ? 'groups.dashboard.resources.index' : 'groups.dashboard.resources.show', ['group' => $group] + ($resource->is_home ? [] : ['slug' => $resource->uuid]))
                : route('groups.dashboard.resources.edit', ['group' => $group, 'resource' => $resource]);
        }
        $facts = array_intersect_key($snapshot, array_flip(['resource_version', 'resource_status', 'revision_id', 'revision_summary']));
        // A legacy event never borrows the newest revision's comment. New events
        // carry the exact saved/published revision and its immutable snapshot text.
        if (! isset($snapshot['revision_id'])) {
            unset($facts['revision_summary']);
        }
        if (in_array($log->action, ['group.resources.save', 'group.resources.publish', 'group.resources.restore'], true)
            && ! filled($facts['revision_summary'] ?? null)) {
            $facts['revision_summary'] = __('audit_log.presentation.revision_comment_unavailable');
        }

        return $this->context('resource', $log->subject_id, $title, $url, $facts);
    }

    private function collection(AuditLog $log, array $snapshot, ?GroupResourceCollection $collection, Collection $visibleCollections, Group $group): ?array
    {
        $title = $snapshot['collection_name'] ?? $collection?->name;
        if (! is_string($title) || $title === '') {
            return null;
        }
        $url = $collection ? ($visibleCollections->has($collection->id)
            ? route('groups.dashboard.resources.collections.show', ['group' => $group, 'collectionSlug' => $collection->slug])
            : route('groups.dashboard.resources.manage', ['group' => $group])) : null;

        return $this->context('collection', $log->subject_id, $title, $url);
    }

    private function image(AuditLog $log, array $snapshot, ?GroupResourceImage $image, bool $exists, User $viewer, array $levels): ?array
    {
        if ($exists && ! $image) {
            return null;
        }
        if ($snapshot !== [] && ! in_array($snapshot['image_access_level'] ?? null, $levels, true)) {
            return null;
        }
        // Once deleted, only admins or the original uploader can read the image
        // snapshot; references needed for other image permissions no longer exist.
        if (! $image && ($snapshot === [] || (! in_array('admin', $levels, true) && ($snapshot['image_uploader_user_id'] ?? null) !== $viewer->id))) {
            return null;
        }
        $title = $snapshot['image_name'] ?? $image?->original_name;

        return is_string($title) && $title !== '' ? $this->context('image', $log->subject_id, $title, null) : null;
    }

    private function context(string $kind, int $id, string $title, ?string $url, array $metadata = []): array
    {
        return ['resource' => compact('kind', 'id', 'title', 'url'), 'metadata' => $metadata];
    }
}
