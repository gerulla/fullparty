<?php

namespace App\Services\Groups\Resources;

use App\Http\Resources\Groups\ResourceReaderHistoryResource;
use App\Models\GroupResource;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResourceReaderHistoryService
{
    public function __construct(private readonly GroupResourcePolicy $policy) {}

    public function preview(GroupResource $resource, Request $request): array
    {
        $revisions = $this->readerQuery($resource, $request)->limit(4)->get();

        return [
            'data' => ResourceReaderHistoryResource::collection($revisions->take(3))->resolve($request),
            'has_more' => $revisions->count() > 3,
        ];
    }

    public function remaining(GroupResource $resource, Request $request): array
    {
        $data = $request->validate(['before' => ['nullable', 'regex:/^(?:publication-)?[1-9][0-9]*$/D']]);
        $query = $this->readerQuery($resource, $request);
        if (isset($data['before'])) {
            $before = (string) $data['before'];
            $publication = str_starts_with($before, 'publication-');
            $cursor = (clone $query)->where('publication', (int) $publication)->where('id', $publication ? substr($before, 12) : $before)->first();
            abort_unless($cursor, 422);
            $query->whereRowValues(['created_at', 'revision_id', 'publication', 'id'], '<', [$cursor->created_at, $cursor->revision_id, $cursor->publication, $cursor->id]);
        }
        $revisions = $query->get();

        return ResourceReaderHistoryResource::collection($revisions)->resolve($request);
    }

    public function management(GroupResource $resource, User $user): array
    {
        return ResourceReaderHistoryResource::collection($this->query($resource, $this->policy->levels($user, $resource->group), true)->get())->resolve();
    }

    private function readerQuery(GroupResource $resource, Request $request): Builder
    {
        $levels = $request->routeIs('public-resources.*') ? ['everyone'] : $this->policy->levels($request->user(), $resource->group);

        return $this->query($resource, $levels);
    }

    private function query(GroupResource $resource, array $levels, bool $manage = false): Builder
    {
        // Publication events reference saved revisions without duplicating their content.
        $revisions = DB::table('group_resource_revisions as revisions')->where('revisions.resource_id', $resource->id)
            ->whereIn('revisions.snapshot->access_level', $levels)->when(! $manage, fn ($query) => $query->where('revisions.state', 'published'))
            ->select(['revisions.id', 'revisions.editor', 'revisions.summary', 'revisions.created_at'])->selectRaw('0 as publication, revisions.id as revision_id');
        $publications = DB::table('group_resource_publications as publications')->join('group_resource_revisions as revisions', 'revisions.id', '=', 'publications.revision_id')
            ->where('revisions.resource_id', $resource->id)->whereIn('revisions.snapshot->access_level', $levels);
        // Keep the same column order for both sides of the union.
        $publications->select(['publications.id', 'publications.publisher as editor'])->selectRaw("'' as summary")
            ->addSelect('publications.created_at')->selectRaw('1 as publication')->addSelect('publications.revision_id');

        return DB::query()->fromSub($revisions->unionAll($publications), 'history')
            ->orderByDesc('created_at')->orderByDesc('revision_id')->orderByDesc('publication')->orderByDesc('id');
    }
}
