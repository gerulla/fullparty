<?php

use App\Models\DiscordGuildIntegration;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\IntegrationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function paginated_resource_command(Group $group, string $name): GroupResource
{
    $resource = GroupResource::factory()->create(['group_id' => $group->id]);
    $embed = ['title' => 'Guide: '.$name];
    $revision = $resource->revisions()->create([
        'editor' => ['name' => 'Editor'],
        'snapshot' => ['command' => ['name' => $name, 'enabled' => true, 'embed' => $embed]],
        'summary' => 'Initial publication.',
        'state' => 'published',
        'published_at' => now(),
    ]);
    $resource->update(['status' => 'published', 'published_revision_id' => $revision->id]);
    $resource->command()->create(['group_id' => $group->id, 'name' => $name, 'enabled' => true, 'embed' => $embed]);

    return $resource;
}

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    DiscordGuildIntegration::create([
        'group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now(),
    ]);
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create([
        'api_token_hash' => IntegrationClient::hashApiToken($token),
        'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ],
    ]);
    $this->withToken($token);
    $this->endpoint = route('api.integrations.resource-commands.index');
    $this->guildBody = ['discord_guild_id' => '123456'];
});

it('paginates commands alphabetically using page numbers from the POST body', function () {
    foreach (['zebra', 'delta', 'alpha', 'charlie', 'bravo'] as $name) {
        paginated_resource_command($this->group, $name);
    }

    $names = [];
    foreach ([1 => ['alpha', 'bravo'], 2 => ['charlie', 'delta'], 3 => ['zebra']] as $page => $expected) {
        $response = $this->postJson($this->endpoint.'?page=99&per_page=100', $this->guildBody + ['page' => $page, 'per_page' => 2])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(count($expected), 'data')
            ->assertJsonPath('meta', [
                'group_id' => $this->group->id,
                'discord_guild_id' => '123456',
                'current_page' => $page,
                'per_page' => 2,
                'total' => 5,
                'last_page' => 3,
                'next_page' => $page < 3 ? $page + 1 : null,
            ])
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('data.0.embed');
        expect(array_column($response->json('data'), 'command_name'))->toBe($expected);
        expect($response->json('data.0.title'))->toBe('Guide: '.$expected[0]);
        $names = array_merge($names, array_column($response->json('data'), 'command_name'));
    }
    expect($names)->toBe(['alpha', 'bravo', 'charlie', 'delta', 'zebra']);

    $this->postJson($this->endpoint, $this->guildBody + ['page' => 4, 'per_page' => 2])
        ->assertOk()->assertExactJson([
            'data' => [],
            'meta' => ['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'current_page' => 4, 'per_page' => 2, 'total' => 5, 'last_page' => 3, 'next_page' => null],
        ]);
});

it('defaults to 25 commands and accepts at most 100 per page', function () {
    for ($index = 1; $index <= 101; $index++) {
        paginated_resource_command($this->group, sprintf('guide-%03d', $index));
    }

    $this->postJson($this->endpoint.'?page=2&per_page=100', $this->guildBody)
        ->assertOk()->assertJsonCount(25, 'data')
        ->assertJsonPath('data.0.command_name', 'guide-001')
        ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('meta.total', 101)->assertJsonPath('meta.last_page', 5)->assertJsonPath('meta.next_page', 2);
    $this->postJson($this->endpoint, $this->guildBody + ['per_page' => 100])
        ->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.next_page', 2);
    $this->postJson($this->endpoint, $this->guildBody + ['page' => 2, 'per_page' => 100])
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.command_name', 'guide-101')->assertJsonPath('meta.next_page', null);
});

it('returns consistent pagination metadata for an empty command list', function () {
    $this->postJson($this->endpoint, $this->guildBody)->assertOk()->assertExactJson([
        'data' => [],
        'meta' => ['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1, 'next_page' => null],
    ]);
});

it('counts only available commands from the linked guild group', function () {
    paginated_resource_command($this->group, 'available');
    paginated_resource_command($this->group, 'disabled')->command()->update(['enabled' => false]);
    paginated_resource_command($this->group, 'archived')->update(['status' => 'archived']);
    paginated_resource_command($this->group, 'draft')->update(['status' => 'draft']);
    paginated_resource_command($this->group, 'unpublished')->update(['published_revision_id' => null]);
    paginated_resource_command(Group::factory()->create(), 'another-group');

    $this->postJson($this->endpoint, $this->guildBody + ['per_page' => 1])
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.command_name', 'available')
        ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.last_page', 1)->assertJsonPath('meta.next_page', null);
});

it('accepts page and per page as form POST data', function () {
    paginated_resource_command($this->group, 'alpha');
    paginated_resource_command($this->group, 'bravo');

    $this->post($this->endpoint, $this->guildBody + ['page' => '2', 'per_page' => '1'])
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.command_name', 'bravo')
        ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.next_page', null);
});

it('rejects invalid command list pagination fields', function (string $field, mixed $value) {
    $this->postJson($this->endpoint, $this->guildBody + [$field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'zero page' => ['page', 0],
    'negative page' => ['page', -1],
    'fractional page' => ['page', 1.5],
    'text page' => ['page', 'invalid'],
    'null page' => ['page', null],
    'array page' => ['page', [1]],
    'oversized page' => ['page', 2147483648],
    'zero page size' => ['per_page', 0],
    'negative page size' => ['per_page', -1],
    'fractional page size' => ['per_page', 1.5],
    'text page size' => ['per_page', 'invalid'],
    'null page size' => ['per_page', null],
    'array page size' => ['per_page', [25]],
    'oversized page size' => ['per_page', 101],
]);
