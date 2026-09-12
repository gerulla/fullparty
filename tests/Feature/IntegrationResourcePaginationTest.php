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
        'snapshot' => ['title' => 'Parent resource: '.$name, 'slug' => $resource->slug, 'access_level' => 'everyone', 'commands' => [['name' => $name, 'enabled' => true, 'embed' => $embed]]],
        'summary' => 'Initial publication.',
        'state' => 'published',
        'published_at' => now(),
    ]);
    $resource->update(['status' => 'published', 'published_revision_id' => $revision->id]);
    $resource->commands()->create(['group_id' => $group->id, 'name' => $name, 'enabled' => true, 'embed' => $embed]);

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
    paginated_resource_command($this->group, 'disabled')->commands()->update(['enabled' => false]);
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

it('rejects invalid pagination fields for lists and command searches', function (string $field, mixed $value) {
    foreach ([$this->endpoint, route('api.integrations.resource-commands.show', ['commandName' => 'drs'])] as $endpoint) {
        $this->postJson($endpoint, $this->guildBody + [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
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

it('lists each embed title independently when multiple commands belong to one resource', function () {
    $resource = paginated_resource_command($this->group, 'drs-tank');
    foreach (['drs-healer' => 'Healing plan', 'drs-melee' => 'Melee loadout'] as $name => $title) {
        $resource->commands()->create(['group_id' => $this->group->id, 'name' => $name, 'enabled' => true, 'embed' => ['title' => $title]]);
    }
    $expected = [
        ['command_name' => 'drs-healer', 'title' => 'Healing plan'],
        ['command_name' => 'drs-melee', 'title' => 'Melee loadout'],
        ['command_name' => 'drs-tank', 'title' => 'Guide: drs-tank'],
    ];
    $this->postJson($this->endpoint, $this->guildBody)->assertOk()->assertJsonPath('data', $expected);
    $this->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'DRS']), $this->guildBody)
        ->assertOk()->assertJsonPath('found', false)->assertJsonPath('data', $expected)->assertJsonPath('meta.total', 3)
        ->assertJsonMissingPath('data.0.embed')->assertJsonMissingPath('data.0.resource_title');
});

it('prefers a case-insensitive exact match over other search matches regardless of requested page', function () {
    paginated_resource_command($this->group, 'drs');
    paginated_resource_command($this->group, 'drs-tank');
    $this->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'DRS']), $this->guildBody + ['page' => 99, 'per_page' => 1])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('found', true)->assertJsonPath('data.command_name', 'drs')->assertJsonPath('data.embed.title', 'Guide: drs')
        ->assertJsonPath('data.embed.author.name', 'Parent resource: drs')
        ->assertJsonPath('data.embed.footer.text', 'FullParty')->assertJsonPath('data.assets', [])
        ->assertJsonStructure(['found', 'data' => ['command_name', 'embed', 'assets', 'components']])->assertJsonMissingPath('meta');
});

it('searches anywhere in command names and paginates only matching published enabled commands from the requested guild', function () {
    foreach (['drs-tank', 'drs-healer', 'drs-melee', 'my-drs-guide', 'tank-drs', 'unrelated'] as $name) {
        paginated_resource_command($this->group, $name);
    }
    paginated_resource_command($this->group, 'drs-disabled')->commands()->update(['enabled' => false]);
    paginated_resource_command($this->group, 'drs-archived')->update(['status' => 'archived']);
    paginated_resource_command($this->group, 'drs-draft')->update(['status' => 'draft']);
    paginated_resource_command($this->group, 'drs-unpublished')->update(['published_revision_id' => null]);
    paginated_resource_command(Group::factory()->create(), 'drs-other-group');
    $url = route('api.integrations.resource-commands.show', ['commandName' => 'DrS']);
    foreach ([1 => ['drs-healer', 'drs-melee'], 2 => ['drs-tank', 'my-drs-guide'], 3 => ['tank-drs'], 4 => []] as $page => $names) {
        $response = $this->postJson($url.'?page=99&per_page=100', $this->guildBody + ['page' => $page, 'per_page' => 2])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('found', false)
            ->assertJsonPath('meta', [
                'group_id' => $this->group->id, 'discord_guild_id' => '123456',
                'current_page' => $page, 'per_page' => 2, 'total' => 5, 'last_page' => 3, 'next_page' => $page < 3 ? $page + 1 : null,
            ])->assertJsonMissingPath('data.0.embed');
        expect(array_column($response->json('data'), 'command_name'))->toBe($names);
    }
    $this->post($url, $this->guildBody + ['page' => '2', 'per_page' => '2'])->assertOk()
        ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', 2)->assertJsonPath('data.0.command_name', 'drs-tank');
});

it('returns an empty search result rather than a 404 when no available command matches', function () {
    paginated_resource_command($this->group, 'other');
    $this->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'missing']), $this->guildBody)->assertOk()->assertExactJson([
        'found' => false, 'data' => [],
        'meta' => ['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1, 'next_page' => null],
    ]);
});

it('ignores disabled exact matches and preserves null titles in fallback results', function () {
    paginated_resource_command($this->group, 'drs')->commands()->update(['enabled' => false]);
    paginated_resource_command($this->group, 'drs-tank')->commands()->update(['embed' => ['description' => 'Untitled tank instructions']]);
    $this->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'drs']), $this->guildBody)->assertOk()
        ->assertJsonPath('found', false)->assertJsonPath('data', [['command_name' => 'drs-tank', 'title' => null]])
        ->assertJsonPath('meta.total', 1);
});

it('applies the same default and maximum page sizes to fallback searches as to lists', function () {
    for ($index = 1; $index <= 26; $index++) {
        paginated_resource_command($this->group, sprintf('drs-%03d', $index));
    }
    $url = route('api.integrations.resource-commands.show', ['commandName' => 'drs']);
    $this->postJson($url.'?page=2&per_page=100', $this->guildBody)->assertOk()->assertJsonPath('found', false)
        ->assertJsonCount(25, 'data')->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 25)->assertJsonPath('meta.next_page', 2);
    $this->postJson($url, $this->guildBody + ['per_page' => 100])->assertOk()->assertJsonCount(26, 'data')->assertJsonPath('meta.next_page', null);
});

it('does not turn invalid guilds or missing bot permissions into search results', function () {
    $url = route('api.integrations.resource-commands.show', ['commandName' => 'drs']);
    $this->postJson($url, ['discord_guild_id' => '999999'])->assertNotFound()->assertJsonMissingPath('found');
    $this->postJson($url.'?discord_guild_id=123456', [])->assertUnprocessable()->assertJsonValidationErrors('discord_guild_id');
    $this->group->features()->update(['resource_hub_enabled' => false]);
    $this->postJson($url, $this->guildBody)->assertNotFound();
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RUNS_READ]]);
    $this->withToken($token)->postJson($url, $this->guildBody)->assertForbidden();
});
