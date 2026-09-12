<?php

namespace App\Services\Groups\Resources;

use App\Http\Resources\Groups\ResourceSummaryResource;
use App\Models\ActivityType;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceImage;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use App\Services\RichText\RichTextDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResourceReaderService
{
    public function __construct(private readonly GroupResourcePolicy $policy, private readonly ResourceLibraryService $libraries, private readonly RichTextDocument $documents, private readonly ResourceEmbedMetadata $embedMetadata, private readonly ResourcePublicationService $publication, private readonly ResourceReaderHistoryService $history, private readonly ResourceDocumentLinks $links) {}

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
        // Reader searches must never expose unpublished draft text.
        if (! $manage) {
            $base->whereHas('publishedRevision', fn ($query) => $this->filterSnapshot($query, 'snapshot', $filters));
        } elseif (! empty($filters['q']) || ! empty($filters['tag']) || ! empty($filters['activity_type_id'])) {
            $base->where(function ($query) use ($filters) {
                $query->where(function ($working) use ($filters) {
                    $working->whereNotNull('working_copy');
                    $this->filterSnapshot($working, 'working_copy', $filters);
                })->orWhere(fn ($published) => $published->whereNull('working_copy')->whereHas('publishedRevision', fn ($q) => $this->filterSnapshot($q, 'snapshot', $filters)));
            });
        }
        $page = $base->with('publishedRevision')->orderByDesc('is_home')->orderByDesc('is_pinned')->orderBy('sort_order')->orderBy('id')->paginate(50);
        $items = $page->getCollection()->map(function ($resource) use ($request, $manage) {
            if (! $manage) {
                return (new ResourceSummaryResource($resource))->resolve($request);
            }
            $snapshot = $resource->working_copy ?? $resource->publishedRevision?->snapshot ?? [];

            return $resource->only(['id', 'is_home', 'slug', 'collection_id', 'status', 'access_level', 'management_access_level', 'version', 'sort_order', 'is_pinned', 'editing_user_id', 'editing_expires_at']) + ['title' => $snapshot['title'] ?? $resource->slug, 'tags' => $snapshot['tags'] ?? [], 'has_unpublished_changes' => $this->publication->hasChanges($resource)];
        });

        return ['library' => $this->libraries->payload($group, $manage), 'collections' => $tree, 'resources' => ['data' => $items, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()], 'filters' => $filters]
            + ($manage ? [] : ['reader' => $this->navigation($group, $request, $public) + ['selected_collection_id' => $selected['id'] ?? null]]);
    }

    private function navigation(Group $group, Request $request, bool $public): array
    {
        $visible = $this->query($group, $public ? null : $request->user(), $public);
        $activityIds = DB::table('group_resource_activity_type')->whereIn('resource_id', (clone $visible)->select('id'))->distinct()->pluck('activity_type_id');
        $activities = ActivityType::whereIn('id', $activityIds)->with('currentPublishedVersion:id,name')->get(['id', 'current_published_version_id'])
            ->map(fn ($type) => ['id' => $type->id, 'name' => $type->currentPublishedVersion?->name ?? []]);
        $recent = (clone $visible)->where('is_home', false)->with('publishedRevision')->orderByDesc('published_at')->orderByDesc('id')->limit(5)->get();
        $pinned = $visible->where('is_home', false)->where('is_pinned', true)->with('publishedRevision')->orderBy('sort_order')->orderBy('id')->get();

        return [
            'activities' => $activities,
            'recent_resources' => ResourceSummaryResource::collection($recent)->resolve($request),
            'pinned_resources' => ResourceSummaryResource::collection($pinned)->resolve($request),
        ];
    }

    public function resolve(Group $group, string $slug, ?User $user, bool $public = false): GroupResource
    {
        if (Str::isUuid($slug) && ($resource = $this->query($group, $user, $public)->where('uuid', $slug)->with('publishedRevision')->first())) {
            return $resource;
        }
        $id = DB::table('group_resource_slugs')->where('group_id', $group->id)->where('slug', $slug)->value('resource_id');

        return $this->query($group, $user, $public)->with('publishedRevision')->findOrFail($id);
    }

    public function home(Group $group, Request $request, bool $public = false): array
    {
        return $this->detail($this->query($group, $request->user(), $public)->where('is_home', true)->with('publishedRevision')->firstOrFail(), $request);
    }

    private function filterSnapshot(Builder $query, string $column, array $filters): void
    {
        if (($q = trim($filters['q'] ?? '')) !== '') {
            $pattern = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q)).'%';
            $tagPattern = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower(ltrim($q, '#'))).'%';
            $query->where(fn ($search) => $search->whereLike($column.'->title', $pattern)
                ->orWhereLike($column.'->description', $pattern)->orWhereLike($column.'->body_text', $pattern)
                ->when(trim($q, '#') !== '', fn ($tags) => $this->searchTags($tags, $column, $tagPattern)));
        }
        if ($tag = $filters['tag'] ?? null) {
            $query->whereJsonContains($column.'->tags', mb_strtolower($tag));
        }
        if ($activity = $filters['activity_type_id'] ?? null) {
            $query->whereJsonContains($column.'->activity_type_ids', (int) $activity);
        }
    }

    private function searchTags(Builder $query, string $column, string $pattern): void
    {
        $tags = $query->getQuery()->getGrammar()->wrap($column.'->tags');
        // Search decoded tag values so non-Latin tags are not compared as JSON escape sequences.
        if ($query->getConnection()->getDriverName() === 'pgsql') {
            $query->orWhereRaw("exists (select 1 from jsonb_array_elements_text(({$tags})::jsonb) as resource_search_tag(value) where value ilike ?)", [$pattern]);
        } else {
            $query->orWhereRaw("exists (select 1 from json_each({$tags}) as resource_search_tag where resource_search_tag.value like ? escape '\\')", [$pattern]);
        }
    }

    public function detail(GroupResource $resource, Request $request): array
    {
        $snapshot = $resource->publishedRevision->snapshot;
        $level = array_search($resource->access_level, GroupResource::ACCESS_LEVELS, true);
        $editors = $resource->revisions()->where('state', 'published')->get(['editor_user_id', 'editor', 'snapshot'])->filter(fn ($revision) => array_search($revision->snapshot['access_level'], GroupResource::ACCESS_LEVELS, true) <= $level)->unique('editor_user_id')->pluck('editor')->values();

        $images = GroupResourceImage::where('group_id', $resource->group_id)->whereIn('uuid', $snapshot['image_ids'] ?? [])->get(['uuid', 'width', 'height', 'mime_type', 'alt_text', 'caption'])->map(fn ($image) => $image->toArray() + ['url' => '/resource-assets/'.$image->uuid]);
        $public = $request->routeIs('public-resources.*');
        $linked = $this->query($resource->group, $public ? null : $request->user(), $public)->whereIn('uuid', $this->links->ids($snapshot['body']))->with('publishedRevision')->get();
        $related = $this->query($resource->group, $public ? null : $request->user(), $public)->where('collection_id', $resource->collection_id)->whereKeyNot($resource->id)->with('publishedRevision')->orderByDesc('is_pinned')->orderBy('sort_order')->limit(6)->get();

        return (new ResourceSummaryResource($resource))->resolve($request) + [
            'body' => $snapshot['body'], 'body_html' => $this->documents->html($snapshot['body'], resourceBlocks: true), 'images' => $images, 'editors' => $editors,
            'linked_resources' => ResourceSummaryResource::collection($linked)->resolve($request),
            'related_resources' => ResourceSummaryResource::collection($related)->resolve($request), 'public_url' => $this->libraries->publicUrl($resource),
            'history' => $this->history->preview($resource, $request),
            'commands' => collect($snapshot['commands'] ?? [])->filter(fn ($command) => $command['enabled'] ?? false)
                ->map(fn ($command) => ['name' => $command['name'], 'title' => $command['embed']['title'] ?? ''])->values()->all(),
        ];
    }

    public function managementDetail(Group $group, GroupResource $resource, User $user): array
    {
        abort_unless((int) $resource->group_id === (int) $group->id, 404);
        abort_unless($this->policy->manage($user, $resource), 403);
        $resource->load(['publishedRevision', 'latestRevision']);

        return $resource->only(['id', 'uuid', 'is_home', 'is_pinned', 'collection_id', 'slug', 'status', 'version', 'sort_order', 'updated_at', 'editing_user_id', 'editing_expires_at']) + [
            'has_unpublished_changes' => $this->publication->hasChanges($resource),
            'can_publish' => $this->publication->canPublish($resource),
            'reader_urls' => $this->readerUrls($group, $resource),
            'working_copy' => $this->embedMetadata->present($group, $resource->working_copy, $resource->updated_at->toIso8601String(), $resource),
            'published' => $this->embedMetadata->present($group, $resource->publishedRevision?->snapshot, ($resource->publishedRevision?->created_at ?? $resource->updated_at)->toIso8601String(), $resource),
            'history' => $this->history->management($resource, $user),
        ];
    }

    public function workspace(Group $group, User $user): array
    {
        // The tree needs every visible file, but bodies and full embeds load only on selection.
        $resources = $this->query($group, $user, manage: true)->with(['publishedRevision', 'latestRevision'])
            ->orderByDesc('is_home')->orderBy('sort_order')->orderBy('id')->lazy(100)->map(function ($resource) use ($group) {
                $snapshot = $resource->working_copy ?? $resource->publishedRevision?->snapshot ?? [];

                return $resource->only(['id', 'uuid', 'is_home', 'is_pinned', 'collection_id', 'slug', 'status', 'version', 'sort_order', 'updated_at']) + [
                    'has_unpublished_changes' => $this->publication->hasChanges($resource),
                    'can_publish' => $this->publication->canPublish($resource),
                    'reader_urls' => $this->readerUrls($group, $resource),
                    'summary' => array_intersect_key($snapshot, array_flip(['title', 'description', 'access_level', 'tags', 'author', 'activity_type_ids', 'metadata_image_id'])),
                    'commands' => array_map(fn ($command) => array_intersect_key($command, array_flip(['name', 'enabled'])), $snapshot['commands'] ?? []),
                ];
            })->values()->all();

        return [
            'editor_user_id' => $user->id,
            'pin_limit' => GroupResource::MAX_PINS,
            'resources' => $resources,
            'embed_context' => [
                'group_icon_url' => $this->embedMetadata->groupIconUrl($group),
                'public_base_url' => route('public-resources.index', ['group' => $group->slug]),
            ],
            'authors' => collect([['id' => null, 'name' => $user->name, 'avatar_url' => null]])->concat($user->characters()->whereNotNull('verified_at')->orderBy('name')->get(['id', 'name', 'avatar_url']))->values(),
            'access_levels' => $this->policy->levels($user, $group),
            'activities' => ActivityType::where('is_active', true)->whereNotNull('current_published_version_id')->with('currentPublishedVersion:id,name')->get(['id', 'current_published_version_id'])->map(fn ($type) => [
                'id' => $type->id, 'name' => $type->currentPublishedVersion?->name ?? [],
            ]),
        ];
    }

    public function revision(Group $group, GroupResource $resource, User $user, int $revisionId): array
    {
        abort_unless((int) $resource->group_id === (int) $group->id, 404);
        abort_unless($this->policy->manage($user, $resource), 403);
        $revision = $resource->revisions()->findOrFail($revisionId);
        abort_unless(in_array($revision->snapshot['access_level'], $this->policy->levels($user, $group), true), 404);

        return $revision->only(['id', 'editor', 'summary', 'state', 'created_at', 'published_at']) + [
            'snapshot' => $this->embedMetadata->present($group, $revision->snapshot, $revision->created_at->toIso8601String(), $resource),
        ];
    }

    private function readerUrls(Group $group, GroupResource $resource): ?array
    {
        if ($resource->status !== 'published' || ! $resource->published_revision_id) {
            return null;
        }
        // Reader links use live access and slugs, never unpublished editor metadata.
        $parameters = ['group' => $group->slug] + ($resource->is_home ? [] : ['slug' => $resource->uuid]);

        return [
            'group' => route($resource->is_home ? 'groups.dashboard.resources.index' : 'groups.dashboard.resources.show', $parameters),
            'public' => $resource->access_level === 'everyone'
                ? route($resource->is_home ? 'public-resources.index' : 'public-resources.show', $parameters) : null,
        ];
    }
}
