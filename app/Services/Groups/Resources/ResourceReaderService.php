<?php

namespace App\Services\Groups\Resources;

use App\Http\Resources\Groups\ResourceSummaryResource;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceImage;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResourceReaderService
{
    public function __construct(private readonly GroupResourcePolicy $policy, private readonly ResourceLibraryService $libraries) {}

    public function query(Group $group, ?User $user, bool $public = false, bool $manage = false): Builder
    {
        if ($public) {
            abort_unless($this->libraries->isPublic($group), 404);
            $levels = ['everyone'];
        } else {
            abort_unless($this->policy->library($user, $group), 403);
            abort_if($manage && ! $this->policy->manageLibrary($user, $group), 403);
            $levels = $this->policy->levels($user, $group);
        }

        return GroupResource::where('group_id', $group->id)
            ->whereIn($manage ? 'management_access_level' : 'access_level', $levels)
            ->when(! $manage, fn ($q) => $q->where('status', 'published')->whereNotNull('published_revision_id'));
    }

    public function index(Group $group, Request $request, bool $public = false, bool $manage = false, ?string $collectionSlug = null): array
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'tag' => ['nullable', 'string', 'max:50'], 'activity_type_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);
        $base = $this->query($group, $public ? null : $request->user(), $public, $manage);
        $collections = GroupResourceCollection::where('group_id', $group->id)->orderBy('sort_order')->orderBy('id')->get();
        $counts = (clone $base)->selectRaw('collection_id, count(*) as total')->groupBy('collection_id')->pluck('total', 'collection_id');
        $visible = [];
        $keyed = $collections->keyBy('id');
        foreach ($counts as $id => $count) {
            $seen = [];
            while ($id && isset($keyed[$id]) && ! isset($seen[$id])) {
                $visible[$id] = true;
                $seen[$id] = true;
                $id = $keyed[$id]->parent_id;
            }
        }
        $tree = $collections->filter(fn ($collection) => $manage || isset($visible[$collection->id]))->map(fn ($collection) => $collection->only(['id', 'parent_id', 'name', 'slug', 'icon', 'sort_order', 'is_featured']) + ['resource_count' => (int) ($counts[$collection->id] ?? 0)])->values();
        if ($collectionSlug !== null) {
            $selected = $tree->firstWhere('slug', $collectionSlug);
            abort_unless($selected, 404);
            $base->where('collection_id', $selected['id']);
        }
        // Search uses only the live revision; pending text must not become a search oracle.
        if (! $manage) {
            $base->whereHas('publishedRevision', fn ($query) => $this->filterSnapshot($query, 'snapshot', $filters));
        } elseif (! empty($filters['q']) || ! empty($filters['tag']) || ! empty($filters['activity_type_id'])) {
            $base->where(function ($query) use ($filters) {
                $query->where(function ($working) use ($filters) {
                    $working->whereNotNull('working_copy');
                    $this->filterSnapshot($working, 'working_copy', $filters);
                })->orWhere(fn ($pending) => $pending->whereNull('working_copy')->whereHas('pendingRevision', fn ($q) => $this->filterSnapshot($q, 'snapshot', $filters)))
                    ->orWhere(fn ($published) => $published->whereNull('working_copy')->whereNull('pending_revision_id')->whereHas('publishedRevision', fn ($q) => $this->filterSnapshot($q, 'snapshot', $filters)));
            });
        }
        $page = $base->with(['publishedRevision', 'pendingRevision'])->orderByDesc('is_pinned')->orderBy('sort_order')->orderBy('id')->paginate(50);
        $items = $page->getCollection()->map(function ($resource) use ($request, $manage) {
            if (! $manage) {
                return (new ResourceSummaryResource($resource))->resolve($request);
            }
            $snapshot = $resource->working_copy ?? $resource->pendingRevision?->snapshot ?? $resource->publishedRevision?->snapshot ?? [];

            return $resource->only(['id', 'slug', 'collection_id', 'status', 'access_level', 'management_access_level', 'version', 'sort_order', 'is_pinned', 'pending_revision_id', 'editing_user_id', 'editing_expires_at']) + ['title' => $snapshot['title'] ?? $resource->slug, 'tags' => $snapshot['tags'] ?? []];
        });

        return ['library' => $this->libraries->payload($group, $manage), 'collections' => $tree, 'resources' => ['data' => $items, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'filters' => $filters];
    }

    public function resolve(Group $group, string $slug, ?User $user, bool $public = false): GroupResource
    {
        $id = DB::table('group_resource_slugs')->where('group_id', $group->id)->where('slug', $slug)->value('resource_id');

        return $this->query($group, $user, $public)->with('publishedRevision')->findOrFail($id);
    }

    private function filterSnapshot(Builder $query, string $column, array $filters): void
    {
        if ($q = $filters['q'] ?? null) {
            $pattern = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q)).'%';
            $query->where(fn ($q) => $q->whereLike($column.'->title', $pattern)->orWhereLike($column.'->description', $pattern)->orWhereLike($column.'->body', $pattern));
        }
        if ($tag = $filters['tag'] ?? null) {
            $query->whereJsonContains($column.'->tags', mb_strtolower($tag));
        }
        if ($activity = $filters['activity_type_id'] ?? null) {
            $query->whereJsonContains($column.'->activity_type_ids', (int) $activity);
        }
    }

    public function detail(GroupResource $resource, Request $request): array
    {
        $snapshot = $resource->publishedRevision->snapshot;
        $level = array_search($resource->access_level, GroupResource::ACCESS_LEVELS, true);
        $editors = $resource->revisions()->where('state', 'published')->get(['editor_user_id', 'editor', 'snapshot'])->filter(fn ($revision) => array_search($revision->snapshot['access_level'], GroupResource::ACCESS_LEVELS, true) <= $level)->unique('editor_user_id')->pluck('editor')->values();

        $images = GroupResourceImage::where('resource_id', $resource->id)->whereIn('uuid', $snapshot['image_ids'] ?? [])->get(['uuid', 'width', 'height', 'mime_type', 'alt_text', 'caption'])->map(fn ($image) => $image->toArray() + ['url' => '/resource-assets/'.$image->uuid]);
        $public = $request->routeIs('public-resources.*');
        $related = $this->query($resource->group, $public ? null : $request->user(), $public)->where('collection_id', $resource->collection_id)->whereKeyNot($resource->id)->with('publishedRevision')->orderByDesc('is_pinned')->orderBy('sort_order')->limit(6)->get();

        return (new ResourceSummaryResource($resource))->resolve($request) + [
            'body' => $snapshot['body'], 'images' => $images, 'editors' => $editors,
            'related_resources' => ResourceSummaryResource::collection($related)->resolve($request), 'public_url' => $this->libraries->publicUrl($resource),
        ];
    }

    public function managementDetail(Group $group, GroupResource $resource, User $user): array
    {
        abort_unless((int) $resource->group_id === (int) $group->id, 404);
        abort_unless($this->policy->manage($user, $resource), 403);
        $resource->load(['publishedRevision', 'pendingRevision']);

        return $resource->only(['id', 'collection_id', 'slug', 'status', 'version', 'working_copy', 'editing_user_id', 'editing_expires_at']) + [
            'published' => $resource->publishedRevision?->snapshot, 'pending' => $resource->pendingRevision?->snapshot,
            'history' => $resource->revisions()->latest('id')->get()->filter(fn ($revision) => in_array($revision->snapshot['access_level'], $this->policy->levels($user, $group), true))->map(fn ($revision) => $revision->only(['id', 'editor', 'summary', 'state', 'created_at', 'published_at']))->values(),
        ];
    }

    public function revision(Group $group, GroupResource $resource, User $user, int $revisionId): array
    {
        abort_unless((int) $resource->group_id === (int) $group->id, 404);
        abort_unless($this->policy->manage($user, $resource), 403);
        $revision = $resource->revisions()->findOrFail($revisionId);
        abort_unless(in_array($revision->snapshot['access_level'], $this->policy->levels($user, $group), true), 404);

        return $revision->only(['id', 'editor', 'summary', 'state', 'snapshot', 'created_at', 'published_at']);
    }
}
