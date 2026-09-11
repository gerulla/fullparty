<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Support\Facades\DB;

class ResourceOrganizationService
{
    public function __construct(
        private readonly ResourceLibraryService $libraries,
        private readonly ResourceCollectionService $collections,
        private readonly ResourceWorkflowService $resources,
        private readonly GroupResourcePolicy $policy,
    ) {}

    public function move(Group $group, User $user, array $data): void
    {
        abort_unless($this->policy->manageLibrary($user, $group), 403);
        DB::transaction(function () use ($group, $user, $data) {
            $this->libraries->lock($group);
            $folder = $data['kind'] === 'collection';
            $model = $folder ? GroupResourceCollection::class : GroupResource::class;
            $parent = $folder ? 'parent_id' : 'collection_id';
            $item = $model::where('group_id', $group->id)->findOrFail($data['id']);
            $siblings = $model::where('group_id', $group->id)->where($parent, $data['parent_id'])
                ->whereKeyNot($item->id)->when(! $folder, fn ($q) => $q->where('is_home', false))
                ->orderBy('sort_order')->orderBy('id')->get();
            $index = $siblings->count();
            if (isset($data['before_id'])) {
                $index = $siblings->search(fn ($sibling) => $sibling->id === (int) $data['before_id']);
                abort_if($index === false, 422);
                if (! $folder) {
                    abort_unless($this->policy->manage($user, $siblings[$index]), 404);
                }
            }
            if ($folder) {
                $this->collections->save($group, $user, ['parent_id' => $data['parent_id'], 'sort_order' => $index], $item);
            } else {
                $this->resources->mutate($group, $item, $user, 'organize', [
                    'version' => $data['version'], 'collection_id' => $data['parent_id'], 'sort_order' => $index,
                ]);
            }
            // Only positional ranks change for siblings; content revisions and edit leases stay intact.
            foreach ($siblings as $position => $sibling) {
                $model::whereKey($sibling->id)->update(['sort_order' => $position < $index ? $position : $position + 1]);
            }
        });
    }
}
