<?php

namespace App\Services\Groups\Resources;

use App\Models\DiscordGuildIntegration;
use App\Models\Group;
use App\Models\GroupResourceCommand;
use App\Models\GroupResourceImage;
use App\Models\IntegrationClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ResourceCommandService
{
    public function __construct(private readonly ResourceLibraryService $libraries, private readonly ResourceEmbedMetadata $embedMetadata) {}

    public function group(Request $request, string $discordGuildId): Group
    {
        $client = $request->attributes->get('integration_client');
        abort_unless($client instanceof IntegrationClient && $client->type === IntegrationClient::TYPE_DISCORD_BOT, 403);
        $link = DiscordGuildIntegration::query()
            ->with('group')
            ->where('discord_guild_id', $discordGuildId)
            ->whereNull('removed_at')
            ->whereNotNull('guild_installed_at')
            ->whereNotNull('group_id')
            ->firstOrFail();
        $group = $link->group;
        abort_unless($group && $group->featureEnabled('resource_hub_enabled'), 404);

        return $group;
    }

    public function available(Group $group): Builder
    {
        return GroupResourceCommand::where('group_id', $group->id)->where('enabled', true)
            ->whereHas('resource', fn ($q) => $q->where('group_id', $group->id)->where('status', 'published')->whereNotNull('published_revision_id'));
    }

    public function find(Group $group, string $name): GroupResourceCommand
    {
        return $this->available($group)->with('resource.group')->where('name', strtolower($name))->firstOrFail();
    }

    public function listing(Group $group, string $guildId, int $page = 1, int $perPage = 25, ?string $search = null): array
    {
        $results = $this->available($group)
            ->when($search !== null, fn ($query) => $query->whereLike('name', '%'.addcslashes(strtolower($search), '\\%_').'%'))
            ->orderBy('name')->paginate(perPage: $perPage, columns: ['name', 'embed'], page: $page);

        return [
            'data' => $results->getCollection()->map(fn ($command) => [
                'command_name' => $command->name, 'title' => $command->embed['title'] ?? null,
            ]),
            'meta' => [
                'group_id' => $group->id,
                'discord_guild_id' => $guildId,
                'current_page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'last_page' => $results->lastPage(),
                'next_page' => $results->hasMorePages() ? $results->currentPage() + 1 : null,
            ],
        ];
    }

    public function lookup(Group $group, string $name, string $guildId, int $page = 1, int $perPage = 25): array
    {
        $command = $this->available($group)->with('resource.group')->where('name', strtolower($name))->first();
        if ($command) {
            return ['found' => true, 'data' => $this->payload($command, $guildId)];
        }

        return ['found' => false] + $this->listing($group, $guildId, $page, $perPage, $name);
    }

    public function payload(GroupResourceCommand $command, string $guildId): array
    {
        $embed = $command->embed;
        $snapshot = $command->resource->publishedRevision->snapshot;
        $savedCommand = collect($snapshot['commands'] ?? [])->firstWhere('name', $command->name);
        $embed['author'] = $this->embedMetadata->author($command->resource->group, $snapshot);
        $embed['timestamp'] = $savedCommand['updated_at'] ?? $command->resource->publishedRevision->created_at->toIso8601String();
        $assets = [];
        foreach (['image', 'thumbnail'] as $key) {
            $id = $embed[$key]['asset_id'] ?? null;
            if (! $id) {
                continue;
            }
            $image = GroupResourceImage::where('group_id', $command->group_id)->where('uuid', $id)->firstOrFail();
            $filename = $id.'.'.pathinfo($image->path, PATHINFO_EXTENSION);
            $assets[$id] = ['id' => $id, 'filename' => $filename, 'mime_type' => $image->mime_type, 'url' => route('api.integrations.resource-commands.images.show', ['discordGuildId' => $guildId, 'commandName' => $command->name, 'image' => $id])];
            $embed[$key] = ['url' => 'attachment://'.$filename];
        }
        $embed['footer'] = ['text' => 'FullParty'];
        $url = $this->libraries->publicUrl($command->resource);

        return ['command_name' => $command->name, 'embed' => $embed, 'assets' => array_values($assets), 'components' => $url ? [[
            'type' => 1, 'components' => [['type' => 2, 'style' => 5, 'label' => 'Open Resource', 'url' => $url]],
        ]] : []];
    }
}
