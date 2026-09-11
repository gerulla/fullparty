<?php

namespace App\Services\Groups\Resources;

use App\Models\ActivityType;
use App\Models\GroupResource;
use App\Models\GroupResourceCommand;
use App\Models\GroupResourceRevision;
use App\Models\GroupResourceTag;
use Illuminate\Support\Facades\DB;

class ResourcePublicationService
{
    public function hasChanges(GroupResource $resource): bool
    {
        return $resource->status !== 'published' || ! $resource->publishedRevision
            || ! $this->matches($resource->working_copy ?? $resource->publishedRevision->snapshot, $resource->publishedRevision->snapshot);
    }

    public function matches(array $left, array $right): bool
    {
        return $this->content($left) == $this->content($right);
    }

    private function content(array $snapshot): array
    {
        // Locations and generated metadata are not content edits requiring publication.
        unset($snapshot['collection_id'], $snapshot['body_format'], $snapshot['body_text'], $snapshot['image_ids']);
        foreach ($snapshot['commands'] ?? [] as $index => $command) {
            unset($snapshot['commands'][$index]['updated_at'], $snapshot['commands'][$index]['embed']['timestamp'], $snapshot['commands'][$index]['embed']['author']);
        }

        return $snapshot;
    }

    public function publish(GroupResource $resource, GroupResourceRevision $revision): void
    {
        $snapshot = $revision->snapshot;
        $resource->activityTypes()->sync(ActivityType::whereIn('id', $snapshot['activity_type_ids'])->pluck('id')->all());
        $resource->tags()->sync(array_map(fn ($name) => GroupResourceTag::firstOrCreate(['group_id' => $resource->group_id, 'name' => $name])->id, $snapshot['tags']));
        $commands = $snapshot['commands'] ?? [];
        $resource->commands()->whereNotIn('name', array_column($commands, 'name'))->delete();
        foreach ($commands as $command) {
            GroupResourceCommand::updateOrCreate(['resource_id' => $resource->id, 'name' => $command['name']], [
                'group_id' => $resource->group_id, 'enabled' => $command['enabled'], 'embed' => $command['embed'],
            ]);
        }
        DB::table('group_resource_slugs')->updateOrInsert(['group_id' => $resource->group_id, 'slug' => $snapshot['slug']], ['resource_id' => $resource->id]);
        $revision->update(['state' => 'published', 'published_at' => now()]);
        $resource->fill([
            'published_revision_id' => $revision->id, 'working_copy' => $snapshot,
            'status' => 'published', 'slug' => $snapshot['slug'], 'access_level' => $snapshot['access_level'],
            'management_access_level' => $snapshot['access_level'], 'published_at' => now(), 'archived_at' => null,
        ]);
        $resource->setRelation('publishedRevision', $revision);
    }
}
