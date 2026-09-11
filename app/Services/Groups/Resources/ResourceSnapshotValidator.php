<?php

namespace App\Services\Groups\Resources;

use App\Models\Character;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use App\Services\RichText\RichTextDocument;
use App\Support\Input\TextInputSanitizer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResourceSnapshotValidator
{
    public function __construct(private readonly TextInputSanitizer $sanitizer, private readonly GroupResourcePolicy $policy, private readonly RichTextDocument $documents, private readonly ResourceEmbedMetadata $embedMetadata) {}

    public function validate(Group $group, User $user, array $input, ?GroupResource $resource = null): array
    {
        if (is_array($input['commands'] ?? null)) {
            foreach ($input['commands'] as &$command) {
                if (! is_array($command)) {
                    continue;
                }
                unset($command['updated_at']);
                if (is_array($command['embed'] ?? null)) {
                    unset($command['embed']['author'], $command['embed']['timestamp']);
                }
            }
            unset($command);
        }
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'array'],
            'access_level' => ['required', Rule::in($this->policy->levels($user, $group))],
            'character_id' => ['nullable', 'integer', Rule::exists('characters', 'id')->where('user_id', $user->id)->whereNotNull('verified_at')],
            'tags' => ['present', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:50'],
            'activity_type_ids' => ['present', 'array', 'max:30'],
            'activity_type_ids.*' => ['required', 'integer', 'distinct', Rule::exists('activity_types', 'id')],
            'metadata_image_id' => ['nullable', 'uuid'],
            'command' => ['prohibited'],
            'commands' => ['sometimes', 'array', 'list', 'max:'.GroupResource::MAX_COMMANDS],
            'commands.*' => ['required', 'array:name,enabled,embed'],
            'commands.*.name' => ['required_with:commands.*', 'string', 'max:64', 'regex:/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/'],
            'commands.*.enabled' => ['required_with:commands.*', 'boolean'],
            'commands.*.embed' => ['required_with:commands.*', 'array:title,url,description,color,thumbnail,image,fields'],
            'commands.*.embed.title' => ['nullable', 'string', 'max:256'],
            'commands.*.embed.url' => ['nullable', 'url:http,https', 'max:2048'],
            'commands.*.embed.description' => ['nullable', 'string', 'max:4096'],
            'commands.*.embed.color' => ['nullable', 'integer', 'between:0,16777215'],
            'commands.*.embed.thumbnail' => ['nullable', 'array:url,asset_id'],
            'commands.*.embed.thumbnail.url' => ['nullable', 'url:http,https', 'max:2048'],
            'commands.*.embed.thumbnail.asset_id' => ['nullable', 'uuid'],
            'commands.*.embed.image' => ['nullable', 'array:url,asset_id'],
            'commands.*.embed.image.url' => ['nullable', 'url:http,https', 'max:2048'],
            'commands.*.embed.image.asset_id' => ['nullable', 'uuid'],
            'commands.*.embed.fields' => ['nullable', 'array', 'max:25'],
            'commands.*.embed.fields.*' => ['array:name,value,inline'],
            'commands.*.embed.fields.*.name' => ['required', 'string', 'max:256'],
            'commands.*.embed.fields.*.value' => ['required', 'string', 'max:1024'],
            'commands.*.embed.fields.*.inline' => ['sometimes', 'boolean'],
        ], [], $this->attributes())->validate();
        unset($data['command']);

        $data['title'] = $this->sanitizer->sanitizeSingleLine($data['title']);
        $data['description'] = $this->sanitizer->sanitizeMultiline($data['description'] ?? '');
        $data['body'] = $this->documents->validate($data['body']);
        $data['body_format'] = RichTextDocument::FORMAT;
        $data['body_text'] = $this->documents->text($data['body']);
        $data['tags'] = array_values(array_unique(array_filter(array_map(fn ($tag) => mb_strtolower($this->sanitizer->sanitizeSingleLine($tag)), $data['tags']))));
        $data['activity_type_ids'] = array_map('intval', $data['activity_type_ids']);
        $data['metadata_image_id'] ??= null;
        $character = isset($data['character_id']) ? Character::find($data['character_id']) : null;
        $data['author'] = $character ? $character->only(['id', 'name', 'world', 'datacenter', 'avatar_url']) : ['name' => $user->name];
        unset($data['character_id']);

        $data['commands'] ??= [];
        $names = [];
        foreach ($data['commands'] as $index => &$command) {
            $command['name'] = strtolower($command['name']);
            if ($command['name'] === 'list') {
                throw ValidationException::withMessages(["commands.{$index}.name" => __('resource_errors.command_reserved')]);
            }
            if (in_array($command['name'], $names, true)) {
                throw ValidationException::withMessages(["commands.{$index}.name" => __('resource_errors.name_taken')]);
            }
            $names[] = $command['name'];
            $command['enabled'] = (bool) $command['enabled'];
            $embed = $command['embed'];
            $length = mb_strlen('FullParty') + mb_strlen($embed['title'] ?? '') + mb_strlen($embed['description'] ?? '') + mb_strlen($data['title']);
            foreach ($embed['fields'] ?? [] as $field) {
                $length += mb_strlen($field['name']) + mb_strlen($field['value']);
            }
            if ($length > 6000 || ! ($embed['title'] ?? $embed['description'] ?? $embed['fields'] ?? $embed['image'] ?? $embed['thumbnail'] ?? null)) {
                throw ValidationException::withMessages(["commands.{$index}.embed" => __('resource_errors.embed')]);
            }
            foreach (['image', 'thumbnail'] as $key) {
                if (isset($embed[$key]) && (empty($embed[$key]['url']) === empty($embed[$key]['asset_id']))) {
                    throw ValidationException::withMessages(["commands.{$index}.embed.{$key}" => __('resource_errors.image_reference')]);
                }
            }
            if (isset($embed['color'])) {
                $embed['color'] = (int) $embed['color'];
            }
            if (isset($embed['fields'])) {
                $embed['fields'] = array_map(fn ($field) => ['name' => $field['name'], 'value' => $field['value'], 'inline' => (bool) ($field['inline'] ?? false)], $embed['fields']);
            }
            $command['embed'] = array_filter($embed, fn ($value) => $value !== null);
        }
        unset($command);

        $data = $this->embedMetadata->stamp($group, $data, $resource);

        $imageIds = $this->imageIds($data);
        if ($imageIds !== []) {
            $allowed = $resource && (int) $resource->group_id === (int) $group->id && $this->policy->manage($user, $resource)
                ? $this->policy->manageableImages($user, $group)->whereIn('uuid', $imageIds)->pluck('uuid')->all() : [];
            $denied = array_diff($imageIds, $allowed);
            if ($denied !== []) {
                $errors = [];
                if (in_array($data['metadata_image_id'], $denied, true)) {
                    $errors['metadata_image_id'] = __('resource_errors.image_reference');
                }
                foreach ($data['commands'] as $index => $command) {
                    foreach (['image', 'thumbnail'] as $key) {
                        if (in_array(data_get($command, "embed.{$key}.asset_id"), $denied, true)) {
                            $errors["commands.{$index}.embed.{$key}"] = __('resource_errors.image_reference');
                        }
                    }
                }
                foreach ($this->documents->imageUrls($data['body']) as $url) {
                    if (in_array(basename($url), $denied, true)) {
                        $errors['body'] = __('resource_errors.image_reference');
                    }
                }
                throw ValidationException::withMessages($errors ?: ['body' => __('resource_errors.image_reference')]);
            }
        }
        $data['image_ids'] = $imageIds;

        return $data;
    }

    public function imageIds(array $snapshot): array
    {
        $ids = array_filter([$snapshot['metadata_image_id'] ?? null]);
        foreach ($snapshot['commands'] ?? [] as $command) {
            $ids = array_merge($ids, array_filter([data_get($command, 'embed.image.asset_id'), data_get($command, 'embed.thumbnail.asset_id')]));
        }
        foreach ($this->documents->imageUrls($snapshot['body'] ?? RichTextDocument::empty()) as $url) {
            // Relative managed URLs work on both reader hosts and cannot embed another private library.
            if (! preg_match('~^/resource-assets/([a-f0-9-]{36})$~D', $url, $matches)) {
                throw ValidationException::withMessages(['body' => __('resource_errors.image_reference')]);
            }
            $ids[] = $matches[1];
        }

        return array_values(array_unique($ids));
    }

    private function attributes(): array
    {
        $fields = ['title', 'description', 'body', 'tags', 'activity_type_ids', 'character_id', 'metadata_image_id', 'access_level'];
        $attributes = [];
        foreach ($fields as $field) {
            $attributes[$field] = __('resource_errors.fields.'.$field);
        }
        foreach (['name', 'embed', 'embed.title', 'embed.description', 'embed.url', 'embed.color', 'embed.image', 'embed.thumbnail', 'embed.fields', 'embed.fields.*.name', 'embed.fields.*.value'] as $field) {
            $attributes['commands.*.'.$field] = __('resource_errors.fields.'.str_replace(['.', '*'], ['_', 'item'], $field));
        }

        return $attributes;
    }
}
