<?php

use App\Models\DiscordGuildIntegration;
use App\Models\Group;
use App\Models\GroupResourceLibrary;
use App\Models\IntegrationClient;
use App\Services\Groups\Resources\ResourcePublicationService;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Services\Groups\Resources\ResourceWorkflowService;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'private']);
    $this->owner = $this->group->owner;
    $this->workflow = app(ResourceWorkflowService::class);
    $this->content = [
        'title' => 'Guide', 'slug' => 'guide', 'description' => '', 'body' => RichTextDocument::empty(),
        'access_level' => 'everyone', 'tags' => [], 'activity_type_ids' => [],
        'commands' => [['name' => 'guide', 'enabled' => true, 'embed' => ['title' => 'Preparation'], 'buttons' => [
            ['label' => 'Join Discord', 'url' => 'https://discord.gg/example'],
        ]]],
    ];
    $this->actingAs($this->owner);
});

function resource_button_action($test, string $action, array $data = []): array
{
    return $test->workflow->mutate($test->group, $test->resource, $test->owner, $action, ['version' => $test->resource->fresh()->version] + $data);
}

function resource_button_bot($test): void
{
    DiscordGuildIntegration::create(['group_id' => $test->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    $test->withToken($token);
}

it('publishes ordered link buttons in Discord action rows alongside the automatic resource link', function () {
    $buttons = array_map(fn ($index) => ['label' => 'Link '.$index, 'url' => 'https://example.com/'.$index], range(1, 5));
    $this->content['commands'][0]['buttons'] = $buttons;
    $this->resource = $this->workflow->create($this->group, $this->owner, ['content' => $this->content]);
    $lease = resource_button_action($this, 'acquire')['editing_token'];
    resource_button_action($this, 'save', ['editing_token' => $lease, 'content' => $this->content, 'summary' => 'Add useful links.']);
    resource_button_action($this, 'publish', ['editing_token' => $lease]);
    resource_button_bot($this);

    $fetch = fn () => $this->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'guide']), ['discord_guild_id' => '123456'])->assertOk();
    $components = $fetch()->assertJsonCount(1, 'data.components')->assertJsonMissingPath('data.embed.buttons')->json('data.components');
    expect($components)->toBe([['type' => 1, 'components' => array_map(fn ($button) => ['type' => 2, 'style' => 5] + $button, $buttons)]]);

    $this->library->update(['visibility' => 'public']);
    $components = $fetch()->assertJsonCount(2, 'data.components')->json('data.components');
    expect(array_map(fn ($row) => count($row['components']), $components))->toBe([5, 1]);
    $allButtons = array_merge(...array_column($components, 'components'));
    expect($allButtons[0])->toBe([
        'type' => 2, 'style' => 5, 'label' => __('ui.open_resource'),
        'url' => route('public-resources.show', ['group' => $this->group, 'slug' => $this->resource->uuid]),
    ])->and(array_slice($allButtons, 1))->toBe(array_map(fn ($button) => ['type' => 2, 'style' => 5] + $button, $buttons));

    // Changing library visibility only removes the automatic link.
    $this->library->update(['visibility' => 'private']);
    $fetch()->assertJsonCount(1, 'data.components')->assertJsonCount(5, 'data.components.0.components');
});

it('keeps button edits private until saved and published and restores them with resource history', function () {
    $this->resource = $this->workflow->create($this->group, $this->owner, ['content' => $this->content]);
    $lease = resource_button_action($this, 'acquire')['editing_token'];
    resource_button_action($this, 'save', ['editing_token' => $lease, 'content' => $this->content, 'summary' => 'Initial links.']);
    resource_button_action($this, 'publish', ['editing_token' => $lease]);
    $original = $this->resource->fresh()->publishedRevision;
    resource_button_bot($this);
    $fetch = fn () => $this->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'guide']), ['discord_guild_id' => '123456'])->assertOk();

    $this->travel(1)->minutes();
    $this->content['commands'][0]['buttons'] = [['label' => 'Read strategy', 'url' => 'https://example.com/strategy']];
    resource_button_action($this, 'autosave', ['editing_token' => $lease, 'content' => $this->content]);
    expect(fn () => resource_button_action($this, 'publish', ['editing_token' => $lease]))->toThrow(ValidationException::class);
    $fetch()->assertJsonPath('data.components.0.components.0.label', 'Join Discord')
        ->assertJsonPath('data.embed.timestamp', $original->snapshot['commands'][0]['updated_at']);

    resource_button_action($this, 'save', ['editing_token' => $lease, 'content' => $this->content, 'summary' => 'Update button destination.']);
    $fetch()->assertJsonPath('data.components.0.components.0.label', 'Join Discord');
    $detail = app(ResourceReaderService::class)->managementDetail($this->group, $this->resource->fresh(), $this->owner);
    expect($detail['working_copy']['commands'][0]['buttons'])->toBe($this->content['commands'][0]['buttons'])
        ->and($detail['can_publish'])->toBeTrue();
    resource_button_action($this, 'publish', ['editing_token' => $lease]);
    $fetch()->assertJsonPath('data.components.0.components.0.url', 'https://example.com/strategy')
        ->assertJsonPath('data.embed.timestamp', now()->toIso8601String());

    resource_button_action($this, 'save', ['editing_token' => $lease, 'source_revision_id' => $original->id, 'content' => $original->snapshot, 'summary' => 'Restore original links.']);
    resource_button_action($this, 'publish', ['editing_token' => $lease]);
    $fetch()->assertJsonPath('data.components.0.components.0.url', 'https://discord.gg/example');

    $this->content['commands'][0]['buttons'] = [];
    resource_button_action($this, 'save', ['editing_token' => $lease, 'content' => $this->content, 'summary' => 'Remove custom links.']);
    resource_button_action($this, 'publish', ['editing_token' => $lease]);
    $fetch()->assertJsonCount(0, 'data.components');
});

it('accepts Discord link button length boundaries including localized labels', function () {
    $this->content['commands'][0]['buttons'] = [['label' => str_repeat('日', 80), 'url' => 'https://example.com/'.str_repeat('a', 492)]];
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => $this->content])->assertCreated();
});

it('rejects invalid link buttons and interaction-only settings', function (array $buttons, string $path) {
    $this->content['commands'][0]['buttons'] = $buttons;
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => $this->content])
        ->assertUnprocessable()->assertJsonValidationErrors('commands.0.buttons'.$path);
})->with([
    'too many' => [array_fill(0, 6, ['label' => 'Link', 'url' => 'https://example.com']), ''],
    'non-list' => [['link' => ['label' => 'Link', 'url' => 'https://example.com']], ''],
    'empty label' => [[['label' => '  ', 'url' => 'https://example.com']], '.0.label'],
    'long label' => [[['label' => str_repeat('a', 81), 'url' => 'https://example.com']], '.0.label'],
    'empty URL' => [[['label' => 'Link', 'url' => '']], '.0.url'],
    'long URL' => [[['label' => 'Link', 'url' => 'https://example.com/'.str_repeat('a', 494)]], '.0.url'],
    'relative URL' => [[['label' => 'Link', 'url' => '/guide']], '.0.url'],
    'script URL' => [[['label' => 'Link', 'url' => 'javascript:alert(1)']], '.0.url'],
    'data URL' => [[['label' => 'Link', 'url' => 'data:text/html,test']], '.0.url'],
    'FTP URL' => [[['label' => 'Link', 'url' => 'ftp://example.com']], '.0.url'],
    'custom ID' => [[['label' => 'Link', 'url' => 'https://example.com', 'custom_id' => 'interaction']], '.0'],
    'style override' => [[['label' => 'Link', 'url' => 'https://example.com', 'style' => 4]], '.0'],
    'color override' => [[['label' => 'Link', 'url' => 'https://example.com', 'color' => '#ff0000']], '.0'],
]);

it('does not mark legacy commands changed merely because empty button arrays are added', function () {
    $this->content['commands'][0]['buttons'] = [];
    $this->resource = $this->workflow->create($this->group, $this->owner, ['content' => $this->content]);
    $legacy = $this->resource->working_copy;
    unset($legacy['commands'][0]['buttons']);
    $this->resource->update(['working_copy' => $legacy]);
    $lease = resource_button_action($this, 'acquire')['editing_token'];
    $this->travel(1)->minutes();
    resource_button_action($this, 'autosave', ['editing_token' => $lease, 'content' => $this->content]);
    $current = $this->resource->fresh()->working_copy;
    expect($current['commands'][0]['updated_at'])->toBe($legacy['commands'][0]['updated_at'])
        ->and(app(ResourcePublicationService::class)->matches($legacy, $current))->toBeTrue();
});
