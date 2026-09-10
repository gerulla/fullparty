<?php

namespace App\Services\Groups\Resources;

use App\Models\Character;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use App\Support\Input\TextInputSanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Parser\MarkdownParser;

class ResourceSnapshotValidator
{
    public function __construct(private readonly TextInputSanitizer $sanitizer, private readonly GroupResourcePolicy $policy) {}

    public function validate(Group $group, User $user, array $input, ?GroupResource $resource = null): array
    {
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'body' => ['present', 'nullable', 'string', 'max:200000'],
            'access_level' => ['required', Rule::in($this->policy->levels($user, $group))],
            'character_id' => ['nullable', 'integer', Rule::exists('characters', 'id')->where('user_id', $user->id)->whereNotNull('verified_at')],
            'tags' => ['present', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:50'],
            'activity_type_ids' => ['present', 'array', 'max:30'],
            'activity_type_ids.*' => ['required', 'integer', 'distinct', Rule::exists('activity_types', 'id')],
            'metadata_image_id' => ['nullable', 'uuid'],
            'command' => ['nullable', 'array:name,enabled,embed'],
            'command.name' => ['required_with:command', 'string', 'max:64', 'regex:/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/'],
            'command.enabled' => ['required_with:command', 'boolean'],
            'command.embed' => ['required_with:command', 'array:title,url,description,color,author,thumbnail,image,timestamp,fields'],
            'command.embed.title' => ['nullable', 'string', 'max:256'],
            'command.embed.url' => ['nullable', 'url:http,https', 'max:2048'],
            'command.embed.description' => ['nullable', 'string', 'max:4096'],
            'command.embed.color' => ['nullable', 'integer', 'between:0,16777215'],
            'command.embed.timestamp' => ['nullable', 'date'],
            'command.embed.author' => ['nullable', 'array:name,url,icon_url'],
            'command.embed.author.name' => ['required_with:command.embed.author', 'string', 'max:256'],
            'command.embed.author.url' => ['nullable', 'url:http,https', 'max:2048'],
            'command.embed.author.icon_url' => ['nullable', 'url:http,https', 'max:2048'],
            'command.embed.thumbnail' => ['nullable', 'array:url,asset_id'],
            'command.embed.thumbnail.url' => ['nullable', 'url:http,https', 'max:2048'],
            'command.embed.thumbnail.asset_id' => ['nullable', 'uuid'],
            'command.embed.image' => ['nullable', 'array:url,asset_id'],
            'command.embed.image.url' => ['nullable', 'url:http,https', 'max:2048'],
            'command.embed.image.asset_id' => ['nullable', 'uuid'],
            'command.embed.fields' => ['nullable', 'array', 'max:25'],
            'command.embed.fields.*' => ['array:name,value,inline'],
            'command.embed.fields.*.name' => ['required', 'string', 'max:256'],
            'command.embed.fields.*.value' => ['required', 'string', 'max:1024'],
            'command.embed.fields.*.inline' => ['sometimes', 'boolean'],
        ])->validate();

        $data['title'] = $this->sanitizer->sanitizeSingleLine($data['title']);
        $data['description'] = $this->sanitizer->sanitizeMultiline($data['description'] ?? '');
        $data['body'] = $this->sanitizer->sanitizeMarkdown($data['body'] ?? '') ?? '';
        $data['tags'] = array_values(array_unique(array_filter(array_map(fn ($tag) => mb_strtolower($this->sanitizer->sanitizeSingleLine($tag)), $data['tags']))));
        $data['activity_type_ids'] = array_map('intval', $data['activity_type_ids']);
        $data['metadata_image_id'] ??= null;
        $character = isset($data['character_id']) ? Character::find($data['character_id']) : null;
        $data['author'] = $character ? $character->only(['id', 'name', 'world', 'datacenter', 'avatar_url']) : ['name' => $user->name];
        unset($data['character_id']);

        $data['command'] ??= null;
        if ($data['command']) {
            $data['command']['name'] = strtolower($data['command']['name']);
            if ($data['command']['name'] === 'list') {
                throw ValidationException::withMessages(['command.name' => __('resource_errors.command_reserved')]);
            }
            $data['command']['enabled'] = (bool) $data['command']['enabled'];
            $embed = $data['command']['embed'];
            $length = mb_strlen('FullParty') + mb_strlen($embed['title'] ?? '') + mb_strlen($embed['description'] ?? '') + mb_strlen($embed['author']['name'] ?? '');
            foreach ($embed['fields'] ?? [] as $field) {
                $length += mb_strlen($field['name']) + mb_strlen($field['value']);
            }
            if ($length > 6000 || ! ($embed['title'] ?? $embed['description'] ?? $embed['fields'] ?? $embed['image'] ?? $embed['thumbnail'] ?? null)) {
                throw ValidationException::withMessages(['command.embed' => __('resource_errors.embed')]);
            }
            foreach (['image', 'thumbnail'] as $key) {
                if (isset($embed[$key]) && (empty($embed[$key]['url']) === empty($embed[$key]['asset_id']))) {
                    throw ValidationException::withMessages(["command.embed.{$key}" => __('resource_errors.image_reference')]);
                }
            }
            if (isset($embed['color'])) {
                $embed['color'] = (int) $embed['color'];
            }
            if (isset($embed['timestamp'])) {
                $embed['timestamp'] = Carbon::parse($embed['timestamp'])->utc()->toIso8601String();
            }
            if (isset($embed['fields'])) {
                $embed['fields'] = array_map(fn ($field) => ['name' => $field['name'], 'value' => $field['value'], 'inline' => (bool) ($field['inline'] ?? false)], $embed['fields']);
            }
            $data['command']['embed'] = array_filter($embed, fn ($value) => $value !== null);
        }

        $imageIds = $this->imageIds($data);
        if ($imageIds !== []) {
            $images = GroupResourceImage::query()->where('group_id', $group->id)->whereIn('uuid', $imageIds)->get();
            if ($images->count() !== count($imageIds) || $images->contains(fn ($image) => ! $resource || ! $this->policy->useImage($user, $image, $resource))) {
                throw ValidationException::withMessages(['body' => __('resource_errors.image_reference')]);
            }
        }
        $data['image_ids'] = $imageIds;

        return $data;
    }

    public function imageIds(array $snapshot): array
    {
        $ids = array_filter([$snapshot['metadata_image_id'] ?? null, data_get($snapshot, 'command.embed.image.asset_id'), data_get($snapshot, 'command.embed.thumbnail.asset_id')]);
        $environment = new Environment(['max_nesting_level' => 100]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $walker = (new MarkdownParser($environment))->parse($snapshot['body'] ?? '')->walker();
        while ($event = $walker->next()) {
            if (! $event->isEntering() || ! $event->getNode() instanceof Image) {
                continue;
            }
            $url = $event->getNode()->getUrl();
            // Relative managed URLs work on both reader hosts and cannot embed another private library.
            if (! preg_match('~^/resource-assets/([a-f0-9-]{36})$~D', $url, $matches)) {
                throw ValidationException::withMessages(['body' => __('resource_errors.image_reference')]);
            }
            $ids[] = $matches[1];
        }

        return array_values(array_unique($ids));
    }
}
