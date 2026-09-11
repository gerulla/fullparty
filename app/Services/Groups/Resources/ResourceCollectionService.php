<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResourceCollection;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResourceCollectionService
{
    public function __construct(private readonly ResourceLibraryService $libraries, private readonly GroupResourcePolicy $policy, private readonly ResourceAudit $audit) {}

    public function save(Group $group, User $user, array $data, ?GroupResourceCollection $collection = null): GroupResourceCollection
    {
        abort_unless($this->policy->manageLibrary($user, $group), 403);
        abort_if($collection && (int) $collection->group_id !== (int) $group->id, 404);

        return DB::transaction(function () use ($group, $user, $data, $collection) {
            $this->libraries->lock($group);
            $collection?->refresh();
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $collection?->parent_id;
            $this->assertParent($group, $collection, $parentId);
            if (GroupResourceCollection::where('group_id', $group->id)->where('slug', $data['slug'] ?? $collection?->slug)->when($collection, fn ($q) => $q->whereKeyNot($collection->id))->exists()) {
                throw ValidationException::withMessages(['slug' => __('resource_errors.name_taken')]);
            }
            if (! $collection && ! array_key_exists('sort_order', $data)) {
                $data['sort_order'] = (GroupResourceCollection::where('group_id', $group->id)->where('parent_id', $parentId)->max('sort_order') ?? -1) + 1;
            }
            $collection ??= new GroupResourceCollection(['group_id' => $group->id]);
            $collection->fill($data)->save();
            $this->audit->record($group, $user, $collection, 'collection_saved');

            return $collection;
        });
    }

    public function reorder(Group $group, User $user, GroupResourceCollection $collection, int $offset): EloquentCollection
    {
        abort_unless($this->policy->manageLibrary($user, $group), 403);
        abort_unless((int) $collection->group_id === (int) $group->id, 404);
        abort_unless(in_array($offset, [-1, 1], true), 422);

        return DB::transaction(function () use ($group, $user, $collection, $offset) {
            $this->libraries->lock($group);
            $collection->refresh();
            $siblings = GroupResourceCollection::where('group_id', $group->id)->where('parent_id', $collection->parent_id)
                ->orderBy('sort_order')->orderBy('id')->get();
            $index = $siblings->search(fn ($item) => $item->id === $collection->id);
            $target = $index + $offset;
            if ($target < 0 || $target >= $siblings->count()) {
                return $siblings;
            }
            $items = $siblings->all();
            [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
            foreach ($items as $order => $item) {
                $item->update(['sort_order' => $order]);
            }
            $this->audit->record($group, $user, $collection, 'collection_saved');

            return new EloquentCollection($items);
        });
    }

    public function delete(Group $group, User $user, GroupResourceCollection $collection, ?int $destinationId): void
    {
        abort_unless($this->policy->manageLibrary($user, $group), 403);
        abort_unless((int) $collection->group_id === (int) $group->id, 404);
        DB::transaction(function () use ($group, $user, $collection, $destinationId) {
            $this->libraries->lock($group);
            $collection->refresh();
            if ($destinationId !== null) {
                $this->assertParent($group, $collection, $destinationId);
                foreach ($collection->resources()->get() as $resource) {
                    abort_unless($this->policy->manage($user, $resource), 403);
                    abort_if($resource->editing_expires_at?->isFuture(), 409, __('resource_errors.locked'));
                }
                $collection->resources()->update(['collection_id' => $destinationId, 'version' => DB::raw('version + 1')]);
                $collection->children()->update(['parent_id' => $destinationId]);
            }
            if ($collection->resources()->exists() || $collection->children()->exists()) {
                throw ValidationException::withMessages(['collection' => __('resource_errors.collection_not_empty')]);
            }
            $this->audit->record($group, $user, $collection, 'collection_deleted');
            $collection->delete();
        });
    }

    private function assertParent(Group $group, ?GroupResourceCollection $collection, ?int $parentId): void
    {
        $seen = [];
        while ($parentId !== null) {
            if (($collection && $parentId === (int) $collection->id) || isset($seen[$parentId])) {
                throw ValidationException::withMessages(['parent_id' => __('resource_errors.collection_cycle')]);
            }
            $seen[$parentId] = true;
            $parentId = GroupResourceCollection::where('group_id', $group->id)->findOrFail($parentId)->parent_id;
        }
    }
}
