<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;

class ResourceStarterContent
{
    public function collections(): array
    {
        return [
            'start' => ['name' => $this->t('collections.start'), 'icon' => 'i-lucide-book-open'],
            'examples' => ['name' => $this->t('collections.examples'), 'icon' => 'i-lucide-sparkles'],
            'raid-preparation' => ['name' => $this->t('collections.raid_preparation'), 'icon' => 'i-lucide-swords', 'parent' => 'examples'],
            'organizers' => ['name' => $this->t('collections.organizers'), 'icon' => 'i-lucide-shield'],
        ];
    }

    public function home(Group $group, string $slug): array
    {
        return [
            'title' => 'Home', 'slug' => $slug, 'description' => $this->t('home.description', ['group' => $group->name]),
            'access_level' => 'everyone', 'tags' => [], 'activity_type_ids' => [], 'commands' => [],
            'body' => $this->doc([
                $this->h('home.welcome'), $this->p('home.introduction'),
                $this->quote('example_notice'), $this->h('home.tour'), $this->list('home.steps', 'orderedList'),
                $this->h('home.make_it_yours'), $this->p('home.customize'),
                ['type' => 'horizontalRule'], $this->p('home.reset'),
            ]),
        ];
    }

    public function resources(string $imageId, array $activityIds): array
    {
        $image = ['type' => 'image', 'attrs' => ['src' => '/resource-assets/'.$imageId, 'alt' => $this->t('image_alt'), 'width' => 800]];

        return [
            $this->entry('getting-started', 'start', 'published', 'everyone', [
                $this->quote('example_notice'), $this->h('getting-started.edit_heading'), $this->list('getting-started.edit_steps', 'orderedList'),
                $this->h('getting-started.format_heading'),
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => $this->t('getting-started.bold'), 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => ' / '],
                    ['type' => 'text', 'text' => $this->t('getting-started.italic'), 'marks' => [['type' => 'italic']]],
                    ['type' => 'text', 'text' => ' / '],
                    ['type' => 'text', 'text' => $this->t('getting-started.highlight'), 'marks' => [['type' => 'highlight', 'attrs' => ['color' => '#fde68a']]]],
                ]],
                $this->p('getting-started.format'), $this->h('getting-started.images_heading'), $this->p('getting-started.images'),
                $this->h('getting-started.links_heading'), $this->p('getting-started.links'), $this->p('getting-started.videos'),
                $this->h('getting-started.organize_heading'), $this->list('getting-started.organize'),
                $this->p('getting-started.pins_icons'), $this->p('getting-started.reader_tools'),
            ]),
            $this->entry('raid-preparation', 'raid-preparation', 'published', 'everyone', [
                $this->quote('example_notice'), $this->h('raid-preparation.checklist_heading'), $this->list('raid-preparation.checklist', 'taskList'),
                $this->h('raid-preparation.map_heading'), $image, $this->p('image_caption'),
                $this->h('raid-preparation.table_heading'), $this->table('raid-preparation.table'),
                $this->p('raid-preparation.adapt'),
            ], ['metadata_image_id' => $imageId, 'activity_type_ids' => $activityIds]),
            $this->entry('discord-embeds', 'examples', 'published', 'everyone', [
                $this->quote('example_notice'), $this->h('discord-embeds.heading'), $this->list('discord-embeds.steps', 'orderedList'),
                $this->h('discord-embeds.examples_heading'), $this->p('discord-embeds.examples'),
                ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => "demo-prep\ndemo-map"]]],
                $this->p('discord-embeds.attribution'), $this->quote('discord-embeds.sharing'),
            ], ['commands' => [
                ['name' => 'demo-prep', 'enabled' => true, 'embed' => [
                    'title' => $this->t('embeds.prep_title'), 'description' => $this->t('embeds.prep_description'), 'color' => 8673200,
                    'thumbnail' => ['asset_id' => $imageId], 'fields' => [
                        ['name' => $this->t('embeds.before_title'), 'value' => $this->t('embeds.before_value'), 'inline' => true],
                        ['name' => $this->t('embeds.ready_title'), 'value' => $this->t('embeds.ready_value'), 'inline' => true],
                    ],
                ]],
                ['name' => 'demo-map', 'enabled' => true, 'embed' => [
                    'title' => $this->t('embeds.map_title'), 'description' => $this->t('embeds.map_description'),
                    'color' => 95231, 'image' => ['asset_id' => $imageId],
                ]],
            ]]),
            $this->entry('publishing-and-access', 'organizers', 'published', 'moderator', [
                $this->h('publishing-and-access.workflow_heading'), $this->list('publishing-and-access.workflow', 'orderedList'),
                $this->h('publishing-and-access.access_heading'), $this->table('publishing-and-access.table'),
                $this->p('publishing-and-access.visibility'), $this->quote('discord-embeds.sharing'),
                $this->h('publishing-and-access.history_heading'), $this->p('publishing-and-access.history'),
                $this->h('publishing-and-access.archive_heading'), $this->p('publishing-and-access.archive'),
            ]),
            $this->entry('library-customization', 'organizers', 'published', 'admin', [
                $this->h('library-customization.heading'), $this->list('library-customization.steps', 'orderedList'),
                $this->h('library-customization.uploads_heading'), $this->p('library-customization.uploads'),
                $this->h('library-customization.reset_heading'), $this->p('home.reset'),
            ]),
            $this->entry('practice-draft', null, 'draft', 'everyone', [
                $this->h('practice-draft.heading'), $this->p('practice-draft.introduction'), $this->list('practice-draft.tasks', 'taskList'),
                ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => '/p '.$this->t('practice-draft.macro')]]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'FullParty', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => rtrim(config('app.url'), '/'), 'target' => '_blank']],
                ]]]],
            ]),
            $this->entry('archived-example', 'examples', 'archived', 'everyone', [
                $this->h('archived-example.heading'), $this->p('archived-example.introduction'), $this->list('archived-example.steps', 'orderedList'),
            ]),
        ];
    }

    private function entry(string $key, ?string $collection, string $state, string $access, array $body, array $extra = []): array
    {
        return ['collection' => $collection, 'state' => $state, 'content' => array_replace([
            'title' => $this->t($key.'.title'), 'slug' => 'starter-'.$key, 'description' => $this->t($key.'.description'),
            'access_level' => $access, 'tags' => [$this->t('tag_example'), $this->t('tag_how_to')],
            'activity_type_ids' => [], 'commands' => [], 'body' => $this->doc($body),
        ], $extra)];
    }

    private function t(string $key, array $replace = []): string
    {
        return __('resource_starter.'.$key, $replace);
    }

    private function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    private function p(string $key): array
    {
        return $this->paragraph($this->t($key));
    }

    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function h(string $key): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $this->t($key)]]];
    }

    private function quote(string $key): array
    {
        return ['type' => 'blockquote', 'content' => [$this->p($key)]];
    }

    private function list(string $key, string $type = 'bulletList'): array
    {
        return ['type' => $type, 'content' => array_map(fn ($text) => [
            'type' => $type === 'taskList' ? 'taskItem' : 'listItem', 'content' => [$this->paragraph($text)],
        ] + ($type === 'taskList' ? ['attrs' => ['checked' => false]] : []), __('resource_starter.'.$key))];
    }

    private function table(string $key): array
    {
        $rows = [];
        foreach (__('resource_starter.'.$key) as $index => $row) {
            $rows[] = ['type' => 'tableRow', 'content' => array_map(fn ($text) => [
                'type' => $index === 0 ? 'tableHeader' : 'tableCell', 'content' => [$this->paragraph($text)],
            ], $row)];
        }

        return ['type' => 'table', 'content' => $rows];
    }
}
