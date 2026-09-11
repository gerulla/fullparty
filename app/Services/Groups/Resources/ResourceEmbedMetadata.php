<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
use Illuminate\Support\Arr;

class ResourceEmbedMetadata
{
    public function __construct(private readonly ResourceLibraryService $libraries) {}

    public function groupIconUrl(Group $group): ?string
    {
        $icon = $group->profile_picture_url;
        if (! $icon) {
            return null;
        }
        if (str_starts_with($icon, '/') && ! str_starts_with($icon, '//')) {
            return rtrim(config('app.url'), '/').$icon;
        }

        return in_array(parse_url($icon, PHP_URL_SCHEME), ['https', 'http'], true) ? $icon : null;
    }

    public function author(Group $group, array $snapshot, ?GroupResource $resource = null): array
    {
        $uuid = $resource?->uuid ?? GroupResource::where('group_id', $group->id)->where(function ($query) use ($snapshot) {
            $query->where('slug', $snapshot['slug'])->orWhereIn('id', function ($aliases) use ($snapshot) {
                $aliases->select('resource_id')->from('group_resource_slugs')->where('slug', $snapshot['slug']);
            });
        })->value('uuid');

        return array_filter([
            'name' => $snapshot['title'],
            'url' => $uuid && $snapshot['access_level'] === 'everyone' && $this->libraries->isPublic($group)
                ? route('public-resources.show', ['group' => $group->slug, 'slug' => $uuid]) : null,
            'icon_url' => $this->groupIconUrl($group),
        ], fn ($value) => $value !== null);
    }

    public function stamp(Group $group, array $snapshot, ?GroupResource $resource): array
    {
        $previous = $resource?->working_copy ?? $resource?->publishedRevision?->snapshot ?? [];
        $previousCommands = collect($previous['commands'] ?? [])->keyBy('name');
        $author = $this->author($group, $snapshot, $resource);
        foreach ($snapshot['commands'] as &$command) {
            $old = $previousCommands->get($command['name']);
            // Attribution is derived; only changes to this command's editable content advance its clock.
            $unchanged = $old && $this->editable($old) == $this->editable($command);
            $command['updated_at'] = $unchanged
                ? ($old['updated_at'] ?? $resource->updated_at->toIso8601String())
                : now()->toIso8601String();
            $command['embed']['timestamp'] = $command['updated_at'];
            $command['embed']['author'] = $author;
        }
        unset($command);

        return $snapshot;
    }

    public function present(Group $group, ?array $snapshot, string $fallbackTimestamp, ?GroupResource $resource = null): ?array
    {
        if ($snapshot === null) {
            return null;
        }
        $author = $this->author($group, $snapshot, $resource);
        foreach ($snapshot['commands'] ?? [] as $index => $command) {
            $snapshot['commands'][$index]['embed']['author'] = $author;
            $snapshot['commands'][$index]['embed']['timestamp'] = $command['updated_at'] ?? $fallbackTimestamp;
        }

        return $snapshot;
    }

    private function editable(array $command): array
    {
        $embed = array_filter(Arr::except($command['embed'], ['author', 'timestamp']), fn ($value) => $value !== null);
        $embed['fields'] ??= [];

        return ['name' => $command['name'], 'embed' => $embed];
    }
}
