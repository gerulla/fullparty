<?php

use App\Models\ActivityType;
use App\Models\CharacterClass;
use App\Models\DiscordGuildIntegration;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Support\Integrations\IntegrationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OpenApiContract;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->document = json_decode(file_get_contents(resource_path('openapi/fullparty.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->member = User::factory()->create();
    DiscordUserIntegration::create(['user_id' => $this->member->id, 'discord_user_id' => '123456789012345678', 'user_app_installed_at' => now()]);
    $token = IntegrationClient::makePlainApiToken();
    $this->client = IntegrationClient::factory()->withApiToken($token)->create([
        'scopes' => [IntegrationPermissions::MEMBERS_READ, IntegrationPermissions::MEMBERS_WRITE],
        'allowed_events' => [],
    ]);
    $this->headers = ['Authorization' => 'Bearer '.$token, 'X-FullParty-Discord-User-Id' => '123456789012345678'];
});

it('returns discovery lookups matching the published response contract', function () {
    Group::factory()->create();
    ActivityType::factory()->withPublishedVersion()->create();
    CharacterClass::create(['name' => 'Paladin', 'shorthand' => 'PLD', 'role' => 'tank', 'icon_url' => null]);

    $path = '/api/integrations/v1/runs/lookups';
    $response = $this->getJson($path, $this->headers)->assertOk()
        ->assertJsonCount(1, 'data.groups')->assertJsonCount(1, 'data.activity_types')->assertJsonCount(1, 'data.class_options');

    OpenApiContract::assertMatches(json_decode($response->getContent()), $this->document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'], $this->document);
});

it('returns notification settings matching the published response contract', function () {
    $values = array_fill_keys(['application_notifications', 'run_and_reminder_notifications', 'group_update_notifications', 'assignment_notifications',
        'account_character_notifications', 'system_notice_notifications', 'email_notifications', 'discord_notifications'], false);
    $path = '/api/integrations/v1/me/notification-preferences';
    $response = $this->putJson($path, $values, $this->headers)->assertOk();

    OpenApiContract::assertMatches(json_decode($response->getContent()), $this->document['paths'][$path]['put']['responses']['200']['content']['application/json']['schema'], $this->document);
});

it('documents actual guild settings webhooks including unset channels and roles', function (bool $configured) {
    Http::fake(['https://bot.fullparty.test/events' => Http::response([], 204)]);
    $this->client->update(['outbound_events_url' => 'https://bot.fullparty.test/events', 'allowed_events' => [IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED]]);
    $group = Group::factory()->create(['owner_id' => $this->member->id]);
    DiscordGuildIntegration::create(['group_id' => $group->id, 'discord_guild_id' => '223456789012345678', 'guild_installed_at' => now()]);
    $activityType = ActivityType::factory()->withPublishedVersion()->create();

    $this->actingAs($this->member)->put(route('groups.dashboard.discord-integration.settings.update', $group), [
        'bot_log_channel_id' => $configured ? '111' : null,
        'member_facing_channel_id' => $configured ? '222' : null,
        'template_role_id' => $configured ? '333' : null,
        'moderation_role_id' => $configured ? '444' : null,
        'name_sync_enabled' => $configured,
        'run_role_template_overrides' => $configured ? [['activity_id' => $activityType->id, 'role_id' => '555']] : [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request): bool {
        OpenApiContract::assertMatches(json_decode($request->body()), $this->document['webhooks']['discord.guild.settings_updated']['post']['requestBody']['content']['application/json']['schema'], $this->document);

        return true;
    });
})->with([false, true]);
