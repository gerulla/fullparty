<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceCommand;
use App\Models\GroupResourceRevision;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResourceWorkflowService
{
    public function __construct(
        private readonly ResourceLibraryService $libraries,
        private readonly ResourceSnapshotValidator $validator,
        private readonly GroupResourcePolicy $policy,
        private readonly ResourceAudit $audit,
        private readonly ResourcePublicationService $publication,
        private readonly ResourceEmbedMetadata $metadata,
    ) {}

    public function create(Group $group, User $user, array $data): GroupResource
    {
        abort_unless($this->policy->manageLibrary($user, $group), 403);

        return DB::transaction(function () use ($group, $user, $data) {
            $this->libraries->lock($group);
            $this->assertCollection($group, $data['collection_id'] ?? null);
            $snapshot = $this->validator->validate($group, $user, $data['content']);
            $this->checkNames($group, $snapshot);
            if ($data['is_pinned'] ?? false) {
                $this->assertPinAvailable($group);
            }
            $resource = GroupResource::create([
                'group_id' => $group->id, 'collection_id' => $data['collection_id'] ?? null, 'author_user_id' => $user->id,
                'slug' => $snapshot['slug'], 'access_level' => $snapshot['access_level'], 'management_access_level' => $snapshot['access_level'],
                'sort_order' => $data['sort_order'] ?? (GroupResource::where('group_id', $group->id)->where('collection_id', $data['collection_id'] ?? null)->max('sort_order') ?? -1) + 1, 'is_pinned' => $data['is_pinned'] ?? false, 'working_copy' => $snapshot,
            ]);
            DB::table('group_resource_slugs')->insert(['group_id' => $group->id, 'resource_id' => $resource->id, 'slug' => $snapshot['slug']]);
            $resource->update(['working_copy' => $this->metadata->stamp($group, $snapshot, $resource)]);
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
            abort_unless(GroupResource::whereKey($resource->id)->withAvailableSource(includeHidden: true)->exists(), 404);
            abort_unless((int) $data['version'] === $resource->version, 409, __('resource_errors.stale'));
            if ($data['publish'] ?? false) {
                throw ValidationException::withMessages(['publish' => __('resource_errors.save_before_publish')]);
            }
            abort_if($resource->is_home && (in_array($action, ['organize', 'pin', 'archive', 'unpublish'], true) || isset($data['collection_id'])), 422, __('resource_errors.home_protected'));

            $response = [];
            abort_if($resource->status === 'archived' && ! in_array($action, ['unarchive', 'release'], true) && ! ($action === 'pin' && ! $data['is_pinned']), 409, __('resource_errors.archived'));
            switch ($action) {
                case 'acquire':
                    if ($this->hasLease($resource)) {
                        $this->assertLease($resource, $user, $data);
                    }
                    $token = $this->hasLease($resource) ? $data['editing_token'] : Str::random(64);
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
                case 'autosave':
                case 'restore':
                    $this->assertLease($resource, $user, $data);
                    $sourceRevision = in_array($action, ['save', 'autosave'], true) && isset($data['source_revision_id'])
                        ? $resource->revisions()->findOrFail($data['source_revision_id']) : null;
                    if ($sourceRevision) {
                        abort_unless(in_array($sourceRevision->snapshot['access_level'], $this->policy->levels($user, $group), true), 404);
                    }
                    $content = $action === 'restore'
                        ? $resource->revisions()->where('state', 'published')->findOrFail($data['revision_id'])->snapshot
                        : $data['content'];
                    $snapshot = $this->validator->validate($group, $user, $content, $resource);
                    if ($action === 'restore') {
                        $snapshot['author'] = $content['author'];
                    } elseif (! array_key_exists('character_id', $content)) {
                        $snapshot['author'] = $sourceRevision?->snapshot['author'] ?? $resource->working_copy['author'] ?? $resource->publishedRevision?->snapshot['author'] ?? $snapshot['author'];
                    }
                    $this->checkNames($group, $snapshot, $resource);
                    if (array_key_exists('collection_id', $data)) {
                        $this->assertCollection($group, $data['collection_id']);
                        $resource->collection_id = $data['collection_id'];
                    }
                    $resource->working_copy = $snapshot;
                    $resource->management_access_level = $this->managementLevel($resource, $snapshot['access_level']);
                    $resource->editing_expires_at = now()->addMinutes(config('group_resources.editing_lease_minutes'));
                    if ($action === 'autosave') {
                        break;
                    }
                    $this->recordRevision($resource, $user, $snapshot, $data['summary'] ?? null);
                    break;
                case 'publish':
                    if ($this->hasLease($resource)) {
                        $this->assertLease($resource, $user, $data);
                    }
                    $snapshot = $resource->working_copy ?? $resource->publishedRevision?->snapshot;
                    abort_unless($snapshot !== null, 409);
                    $this->checkNames($group, $snapshot, $resource);
                    if (! $this->publication->hasChanges($resource)) {
                        break;
                    }
                    $revision = $this->publication->savedRevision($resource);
                    if (! $revision) {
                        throw ValidationException::withMessages(['resource' => __('resource_errors.save_before_publish')]);
                    }
                    $this->publication->publish($resource, $revision, $user);
                    break;
                case 'archive':
                case 'unpublish':
                    abort_if($this->hasLease($resource), 409, __('resource_errors.locked'));
                    $resource->status = $action === 'archive' ? 'archived' : 'draft';
                    $resource->archived_at = $action === 'archive' ? now() : null;
                    if ($action === 'archive') {
                        $resource->is_pinned = false;
                    }
                    break;
                case 'unarchive':
                    abort_unless($resource->status === 'archived', 409);
                    $resource->status = 'draft';
                    $resource->archived_at = null;
                    break;
                case 'organize':
                    abort_if($this->hasLease($resource), 409, __('resource_errors.locked'));
                    $this->assertCollection($group, $data['collection_id']);
                    if (($data['is_pinned'] ?? false) && ! $resource->is_pinned) {
                        $this->assertPinAvailable($group);
                    }
                    $resource->fill(array_intersect_key($data, array_flip(['collection_id', 'sort_order', 'is_pinned'])));
                    break;
                case 'pin':
                    if ($this->hasLease($resource)) {
                        $this->assertLease($resource, $user, $data);
                    }
                    if ($data['is_pinned'] && ! $resource->is_pinned) {
                        $this->assertPinAvailable($group);
                    }
                    $resource->is_pinned = $data['is_pinned'];
                    break;
                default:
                    abort(404);
            }
            $resource->version++;
            $resource->timestamps = ! in_array($action, ['heartbeat', 'acquire', 'release', 'pin'], true);
            $resource->save();
            if (! in_array($action, ['heartbeat', 'acquire', 'release'], true)) {
                $auditAction = $action === 'pin' ? ($resource->is_pinned ? 'pin' : 'unpin') : $action;
                $this->audit->record($group, $user, $resource, $auditAction === 'autosave' ? 'save' : ($auditAction === 'unarchive' ? 'unpublish' : $auditAction));
            }

            return $response + ['id' => $resource->id, 'version' => $resource->version, 'status' => $resource->status, 'editing_expires_at' => $resource->editing_expires_at?->toIso8601String()];
        });
    }

    private function assertPinAvailable(Group $group): void
    {
        // The library/group lock serializes this count with every resource mutation.
        if (GroupResource::where('group_id', $group->id)->where('is_pinned', true)->count() >= GroupResource::MAX_PINS) {
            throw ValidationException::withMessages(['is_pinned' => __('resource_errors.pin_limit', ['limit' => GroupResource::MAX_PINS])]);
        }
    }

    private function recordRevision(GroupResource $resource, User $user, array $snapshot, ?string $summary): GroupResourceRevision
    {
        $snapshot['collection_id'] = $resource->collection_id;
        $summary = Validator::make(['summary' => trim($summary ?? '')], [
            'summary' => ['required', 'string', 'max:300', 'regex:/^[^\r\n]+$/u'],
        ])->validate()['summary'];
        $character = $user->primaryCharacter;

        return $resource->revisions()->create([
            'editor_user_id' => $user->id,
            'editor' => ['name' => $user->name, 'avatar_url' => $character?->avatar_url],
            'snapshot' => $snapshot, 'summary' => $summary, 'state' => 'draft',
        ]);
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
        Validator::make(['commands' => $snapshot['commands'] ?? []], ['commands' => ['array', 'max:'.GroupResource::MAX_COMMANDS]])->validate();
        abort_if($resource?->is_home && ($snapshot['access_level'] !== 'everyone' || $snapshot['slug'] !== $resource->slug), 422, __('resource_errors.home_protected'));
        $slugTaken = DB::table('group_resource_slugs')->where('group_id', $group->id)->where('slug', $snapshot['slug'])->when($resource, fn ($q) => $q->where('resource_id', '!=', $resource->id))->exists();
        $names = array_column($snapshot['commands'] ?? [], 'name');
        $reserved = GroupResourceCommand::where('group_id', $group->id)->whereIn('name', $names)->when($resource, fn ($q) => $q->where('resource_id', '!=', $resource->id))->pluck('name')->all();
        // Draft names are reservations too. The group row serializes this check with writes.
        foreach (GroupResource::where('group_id', $group->id)->when($resource, fn ($q) => $q->whereKeyNot($resource->id))->get() as $other) {
            $copy = $other->working_copy;
            $slugTaken = $slugTaken || ($copy['slug'] ?? null) === $snapshot['slug'];
            $reserved = array_merge($reserved, array_column($copy['commands'] ?? [], 'name'));
        }
        if ($slugTaken) {
            throw ValidationException::withMessages(['content.slug' => __('resource_errors.name_taken')]);
        }
        foreach ($names as $index => $name) {
            if (in_array($name, $reserved, true)) {
                throw ValidationException::withMessages(["content.commands.{$index}.name" => __('resource_errors.name_taken')]);
            }
        }
    }

    private function assertCollection(Group $group, ?int $collectionId): void
    {
        if ($collectionId !== null) {
            GroupResourceCollection::where('group_id', $group->id)->findOrFail($collectionId);
        }
    }
}
