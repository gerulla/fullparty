<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use Illuminate\Validation\ValidationException;

class ResourceDocumentLinks
{
    public function __construct(private readonly GroupResourcePolicy $policy) {}

    public function ids(array $document): array
    {
        $ids = [];
        $walk = function (array $node) use (&$walk, &$ids): void {
            if (($node['type'] ?? '') === 'resourceLink' && is_string($node['attrs']['resourceId'] ?? null)) {
                $ids[] = $node['attrs']['resourceId'];
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($document);

        return array_values(array_unique($ids));
    }

    public function validate(Group $group, User $user, array $document, ?GroupResource $source): void
    {
        // Existing links may outlive their targets or the author's access to them.
        $existing = $source ? array_merge($this->ids($source->working_copy['body'] ?? []), $this->ids($source->publishedRevision?->snapshot['body'] ?? [])) : [];
        $added = array_diff($this->ids($document), $existing);
        if (! $added) {
            return;
        }
        $levels = $this->policy->levels($user, $group);
        $allowed = GroupResource::where('group_id', $group->id)->whereIn('uuid', $added)
            ->where('status', '!=', 'archived')->where(function ($query) use ($levels) {
                $query->whereIn('management_access_level', $levels)
                    ->orWhere(fn ($published) => $published->where('status', 'published')->whereNotNull('published_revision_id')->whereIn('access_level', $levels));
            })->pluck('uuid')->all();
        $denied = array_diff($added, $allowed);
        if ($denied && $source) {
            // Restoring an accessible older revision must also tolerate removed targets.
            foreach ($source->revisions()->whereIn('snapshot->access_level', $levels)->select('id', 'snapshot')->lazy(100) as $revision) {
                $denied = array_diff($denied, $this->ids($revision->snapshot['body'] ?? []));
                if (! $denied) {
                    break;
                }
            }
        }
        if ($denied) {
            throw ValidationException::withMessages(['body' => __('resource_errors.resource_reference')]);
        }
    }
}
