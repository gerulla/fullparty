<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
use App\Services\RichText\RichTextDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResourceHomeService
{
    public function ensure(Group $group): GroupResource
    {
        return DB::transaction(function () use ($group) {
            Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            if ($home = GroupResource::where('group_id', $group->id)->where('is_home', true)->first()) {
                return $home;
            }
            $slug = 'home';
            while (DB::table('group_resource_slugs')->where('group_id', $group->id)->where('slug', $slug)->exists()
                || GroupResource::where('group_id', $group->id)->where(fn ($query) => $query->where('slug', $slug)->orWhere('working_copy->slug', $slug))->exists()) {
                $slug = 'home-'.Str::lower(Str::random(8));
            }
            $author = ['id' => null, 'name' => $group->owner?->name ?? $group->name, 'avatar_url' => null];
            $snapshot = [
                'title' => 'Home', 'slug' => $slug, 'description' => '', 'collection_id' => null,
                'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
                'body_format' => RichTextDocument::FORMAT, 'body_text' => '', 'access_level' => 'everyone',
                'author' => $author, 'tags' => [], 'activity_type_ids' => [], 'image_ids' => [],
                'metadata_image_id' => null, 'commands' => [],
            ];
            $home = GroupResource::create([
                'group_id' => $group->id, 'collection_id' => null, 'author_user_id' => $group->owner_id,
                'is_home' => true, 'slug' => $slug, 'status' => 'published', 'published_at' => now(),
            ]);
            $revision = $home->revisions()->create([
                'editor_user_id' => $group->owner_id, 'editor' => $author, 'snapshot' => $snapshot,
                'summary' => Str::limit(__('resource_history.created', ['author' => $author['name'], 'title' => 'Home']), 300, ''),
                'state' => 'published', 'published_at' => now(),
            ]);
            $home->update(['published_revision_id' => $revision->id]);
            DB::table('group_resource_slugs')->insert(['group_id' => $group->id, 'resource_id' => $home->id, 'slug' => $slug]);

            return $home;
        });
    }
}
