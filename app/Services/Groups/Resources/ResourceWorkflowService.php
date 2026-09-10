<?php

namespace App\Services\Groups\Resources;

use App\Models\ActivityType;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceCommand;
use App\Models\GroupResourceTag;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResourceWorkflowService
{
    public function __construct(
        private readonly ResourceLibraryService $libraries,
        private readonly ResourceSnapshotValidator $validator,
        private readonly GroupResourcePolicy $policy,
        private readonly ResourceAudit $audit,
    ) {}

    public function create(Group $group, User $user, array $data): GroupResource
    {
        abort_unless($this->policy->manageLibrary($user, $group), 403);

        return DB::transaction(function () use ($group, $user, $data) {
            $this->libraries->lock($group);
            GroupResourceCollection::where('group_id', $group->id)->findOrFail($data['collection_id']);
            $snapshot = $this->validator->validate($group, $user, $data['content']);
            $this->checkNames($group, $snapshot);
            $resource = GroupResource::create([
                'group_id' => $group->id, 'collection_id' => $data['collection_id'], 'author_user_id' => $user->id,
                'slug' => $snapshot['slug'], 'access_level' => $snapshot['access_level'], 'management_access_level' => $snapshot['access_level'],
                'sort_order' => $data['sort_order'] ?? 0, 'is_pinned' => $data['is_pinned'] ?? false, 'working_copy' => $snapshot,
            ]);
            DB::table('group_resource_slugs')->insert(['group_id' => $group->id, 'resource_id' => $resource->id, 'slug' => $snapshot['slug']]);
            $this->audit->record($group, $user, $resource, 'created');

            return $resource;
        });
    }

    public function mutate(Group $group, GroupResource $resource, User $user, string $action, array $data): array
    {
        abort_unless((int) $resource->group_id === (int) $group->id, 404);

        return DB::transaction(function () use ($group, $resource, $user, $action, $data) {
            $this->libraries->lock($group);
            $resource = GroupResource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->policy->manage($user, $resource), 403);
            abort_unless((int) $data['version'] === $resource->version, 409, __('resource_errors.stale'));

            if (in_array($action, ['acquire', 'save', 'submit', 'restore', 'heartbeat', 'release', 'organize'], true)) {
                abort_if($resource->pending_revision_id !== null, 409, __('resource_errors.pending'));
            }
            $response = [];
            switch ($action) {
                case 'acquire':
                    abort_if($this->hasLease($resource), 409, __('resource_errors.locked'));
                    $token = Str::random(64);
                    $resource->editing_user_id = $user->id;
                    $resource->editing_token_hash = hash('sha256', $token);
                    $resource->editing_expires_at = now()->addMinutes(config('group_resources.editing_lease_minutes'));
                    $resource->working_copy ??= $resource->publishedRevision?->snapshot;
                    $response['editing_token'] = $token;
                    break;
                case 'heartbeat':
                    $this->assertLease($resource, $user, $data);
                    $resource->editing_expires_at = now()->addMinutes(config('group_resources.editing_lease_minutes'));
                    break;
                case 'release':
                    $this->assertLease($resource, $user, $data);
                    $this->clearLease($resource);
                    break;
                case 'save':
                case 'restore':
                    $this->assertLease($resource, $user, $data);
                    $content = $action === 'restore'
                        ? $resource->revisions()->where('state', 'published')->findOrFail($data['revision_id'])->snapshot
                        : $data['content'];
                    $snapshot = $this->validator->validate($group, $user, $content, $resource);
                    if ($action === 'restore') {
                        $snapshot['author'] = $content['author'];
                    } elseif (! array_key_exists('character_id', $content)) {
                        $snapshot['author'] = $resource->working_copy['author'] ?? $resource->publishedRevision?->snapshot['author'] ?? $snapshot['author'];
                    }
                    $this->checkNames($group, $snapshot, $resource);
                    $resource->working_copy = $snapshot;
                    $resource->management_access_level = $this->managementLevel($resource, $snapshot['access_level']);
                    $resource->editing_expires_at = now()->addMinutes(config('group_resources.editing_lease_minutes'));
                    break;
                case 'submit':
                    $this->assertLease($resource, $user, $data);
                    abort_unless($resource->working_copy !== null, 409);
                    $this->checkNames($group, $resource->working_copy, $resource);
                    $revision = $resource->revisions()->create([
                        'editor_user_id' => $user->id, 'editor' => ['name' => $user->name],
                        'snapshot' => $resource->working_copy, 'summary' => $data['summary'], 'state' => 'pending',
                    ]);
                    $resource->pending_revision_id = $revision->id;
                    $resource->working_copy = null;
                    $this->clearLease($resource);
                    break;
                case 'publish':
                    abort_unless($resource->pending_revision_id !== null, 409, __('resource_errors.no_pending'));
                    $revision = $resource->revisions()->findOrFail($resource->pending_revision_id);
                    $snapshot = $revision->snapshot;
                    $this->checkNames($group, $snapshot, $resource);
                    $resource->activityTypes()->sync(ActivityType::whereIn('id', $snapshot['activity_type_ids'])->pluck('id')->all());
                    $resource->tags()->sync(array_map(fn ($name) => GroupResourceTag::firstOrCreate(['group_id' => $group->id, 'name' => $name])->id, $snapshot['tags']));
                    $command = $snapshot['command'] ?? null;
                    if ($command) {
                        GroupResourceCommand::updateOrCreate(['resource_id' => $resource->id], [
                            'group_id' => $group->id, 'name' => $command['name'], 'enabled' => $command['enabled'], 'embed' => $command['embed'],
                        ]);
                    } else {
                        $resource->command()->delete();
                    }
                    DB::table('group_resource_slugs')->updateOrInsert(['group_id' => $group->id, 'slug' => $snapshot['slug']], ['resource_id' => $resource->id]);
                    $revision->update(['state' => 'published', 'published_at' => now()]);
                    $resource->fill([
                        'published_revision_id' => $revision->id, 'pending_revision_id' => null, 'working_copy' => null,
                        'status' => 'published', 'slug' => $snapshot['slug'], 'access_level' => $snapshot['access_level'],
                        'management_access_level' => $snapshot['access_level'], 'published_at' => now(), 'archived_at' => null,
                    ]);
                    $this->clearLease($resource);
                    break;
                case 'discard':
                    if ($this->hasLease($resource)) {
                        $this->assertLease($resource, $user, $data);
                    }
                    if ($resource->pending_revision_id) {
                        $resource->revisions()->whereKey($resource->pending_revision_id)->update(['state' => 'discarded']);
                    }
                    $resource->pending_revision_id = null;
                    $resource->working_copy = $resource->publishedRevision?->snapshot;
                    $resource->management_access_level = $resource->access_level;
                    $this->clearLease($resource);
                    break;
                case 'archive':
                case 'unpublish':
                    abort_if($resource->pending_revision_id || $this->hasLease($resource), 409, __('resource_errors.pending'));
                    $resource->status = $action === 'archive' ? 'archived' : 'draft';
                    $resource->archived_at = $action === 'archive' ? now() : null;
                    break;
                case 'organize':
                    abort_if($this->hasLease($resource), 409, __('resource_errors.locked'));
                    GroupResourceCollection::where('group_id', $group->id)->findOrFail($data['collection_id']);
                    $resource->fill(array_intersect_key($data, array_flip(['collection_id', 'sort_order', 'is_pinned'])));
                    break;
                default:
                    abort(404);
            }
            $resource->version++;
            $resource->save();
            if (! in_array($action, ['heartbeat', 'acquire', 'release'], true)) {
                $this->audit->record($group, $user, $resource, $action);
            }

            return $response + ['id' => $resource->id, 'version' => $resource->version, 'status' => $resource->status, 'pending_revision_id' => $resource->pending_revision_id, 'editing_expires_at' => $resource->editing_expires_at?->toIso8601String()];
        });
    }

    private function managementLevel(GroupResource $resource, string $workingLevel): string
    {
        return GroupResource::ACCESS_LEVELS[max(array_search($resource->access_level, GroupResource::ACCESS_LEVELS), array_search($workingLevel, GroupResource::ACCESS_LEVELS))];
    }

    private function hasLease(GroupResource $resource): bool
    {
        return $resource->editing_token_hash !== null && $resource->editing_expires_at?->isFuture();
    }

    public function assertLease(GroupResource $resource, User $user, array $data): void
    {
        abort_unless($this->hasLease($resource) && (int) $resource->editing_user_id === (int) $user->id && hash_equals($resource->editing_token_hash, hash('sha256', $data['editing_token'] ?? '')), 409, __('resource_errors.locked'));
    }

    private function clearLease(GroupResource $resource): void
    {
        $resource->editing_user_id = null;
        $resource->editing_token_hash = null;
        $resource->editing_expires_at = null;
    }

    private function checkNames(Group $group, array $snapshot, ?GroupResource $resource = null): void
    {
        $slugTaken = DB::table('group_resource_slugs')->where('group_id', $group->id)->where('slug', $snapshot['slug'])->when($resource, fn ($q) => $q->where('resource_id', '!=', $resource->id))->exists();
        $name = data_get($snapshot, 'command.name');
        $commandTaken = $name && GroupResourceCommand::where('group_id', $group->id)->where('name', $name)->when($resource, fn ($q) => $q->where('resource_id', '!=', $resource->id))->exists();
        // Working and pending names are reservations too. The group row serializes this check with writes.
        foreach (GroupResource::where('group_id', $group->id)->when($resource, fn ($q) => $q->whereKeyNot($resource->id))->with('pendingRevision')->get() as $other) {
            $copy = $other->working_copy ?? $other->pendingRevision?->snapshot;
            $slugTaken = $slugTaken || ($copy['slug'] ?? null) === $snapshot['slug'];
            $commandTaken = $commandTaken || ($name && data_get($copy, 'command.name') === $name);
        }
        if ($slugTaken || $commandTaken) {
            throw ValidationException::withMessages([$slugTaken ? 'content.slug' : 'content.command.name' => __('resource_errors.name_taken')]);
        }
    }
}
