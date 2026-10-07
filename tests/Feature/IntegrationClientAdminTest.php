<?php

use App\Models\IntegrationClient;
use App\Models\IntegrationClientHealthCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('only allows admins to manage integration clients', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.integrations.index'))
        ->assertForbidden();
});

it('renders integration clients for admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    IntegrationClient::factory()->create([
        'name' => 'FullParty Discord Bot',
        'healthcheck_url' => 'https://bot.fullparty.test/health',
    ]);

    IntegrationClient::query()->sole()->healthChecks()->create([
        'status' => IntegrationClientHealthCheck::STATUS_OK,
        'checked_at' => now(),
        'response_status' => 204,
        'duration_ms' => 12,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.integrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Integrations')
            ->where('clients.0.name', 'FullParty Discord Bot')
            ->where('clients.0.healthcheck_url', 'https://bot.fullparty.test/health')
            ->where('clients.0.latest_healthcheck.status', IntegrationClientHealthCheck::STATUS_HEALTHY)
            ->where('clients.0.latest_healthcheck.response_status', 204)
            ->where('clients.0.latest_healthcheck.duration_ms', 12)
            ->where('clients.0.healthcheck_stats.day.uptime', 100)
            ->has('clients.0.healthcheck_stats.day.buckets', 24)
            ->has('clients.0.healthcheck_stats.week.buckets', 28)
            ->where('options.scopes.0', IntegrationClient::SCOPE_RUNS_READ)
            ->where('options.scopes.1', IntegrationClient::SCOPE_USERS_READ)
            ->where('options.scopes.2', IntegrationClient::SCOPE_USERS_WRITE)
            ->where('options.scopes.3', IntegrationClient::SCOPE_GUILDS_WRITE)
            ->where('options.events.3', IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_SOON)
            ->where('options.events.4', IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_NOW)
            ->where('options.events.5', IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED)
            ->where('options.events.6', IntegrationClient::EVENT_DISCORD_GUILD_RUN_CANCELLED)
            ->where('options.events.7', IntegrationClient::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED)
            ->where('options.events.8', IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED)
            ->where('options.events.9', IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED)
            ->where('options.events.10', IntegrationClient::EVENT_DISCORD_GUILD_RUN_PARTICIPANT_SYNC)
        );
});

it('reports degraded integration healthchecks without counting them as downtime', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $client = IntegrationClient::factory()->create([
        'name' => 'FullParty Discord Bot',
        'healthcheck_url' => 'https://bot.fullparty.test/health',
    ]);

    $client->healthChecks()->create([
        'status' => IntegrationClientHealthCheck::STATUS_DEGRADED,
        'checked_at' => now(),
        'response_status' => 200,
        'duration_ms' => 18,
        'error' => 'Recent Failures: degraded (warn: 1, ignored: 3)',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.integrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Integrations')
            ->where('clients.0.latest_healthcheck.status', IntegrationClientHealthCheck::STATUS_DEGRADED)
            ->where('clients.0.healthcheck_stats.day.total', 1)
            ->where('clients.0.healthcheck_stats.day.failed', 0)
            ->where('clients.0.healthcheck_stats.day.degraded', 1)
            ->where('clients.0.healthcheck_stats.day.uptime', 100)
        );
});

it('creates an integration client and flashes one time credentials', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post(route('admin.integrations.store'), [
            'name' => 'FullParty Discord Bot',
            'type' => IntegrationClient::TYPE_DISCORD_BOT,
            'status' => IntegrationClient::STATUS_ACTIVE,
            'outbound_events_url' => 'https://bot.fullparty.test/events',
            'healthcheck_url' => 'https://bot.fullparty.test/health',
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
                IntegrationClient::SCOPE_USERS_READ,
                IntegrationClient::SCOPE_USERS_WRITE,
                IntegrationClient::SCOPE_GUILDS_WRITE,
            ],
            'allowed_events' => [IntegrationClient::EVENT_DISCORD_USER_APP_INSTALLED],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'integration_client_created')
        ->assertSessionHas('flash_data.integration_credentials.api_token')
        ->assertSessionHas('flash_data.integration_credentials.webhook_signing_secret');

    $client = IntegrationClient::query()->sole();

    expect($client->name)->toBe('FullParty Discord Bot')
        ->and($client->healthcheck_url)->toBe('https://bot.fullparty.test/health')
        ->and($client->api_token_hash)->not->toBeNull()
        ->and($client->webhook_signing_secret)->not->toBeNull()
        ->and($client->scopes)->toBe([
            IntegrationClient::SCOPE_RUNS_READ,
            IntegrationClient::SCOPE_USERS_READ,
            IntegrationClient::SCOPE_USERS_WRITE,
            IntegrationClient::SCOPE_GUILDS_WRITE,
        ])
        ->and($client->allowed_events)->toBe([IntegrationClient::EVENT_DISCORD_USER_APP_INSTALLED]);
});

it('regenerates integration credentials separately', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $client = IntegrationClient::factory()->create([
        'api_token_hash' => IntegrationClient::hashApiToken('old-token'),
        'webhook_signing_secret' => 'old-secret',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.integrations.api-token.regenerate', $client))
        ->assertRedirect()
        ->assertSessionHas('success', 'integration_client_api_token_regenerated')
        ->assertSessionHas('flash_data.integration_credentials.api_token');

    expect($client->fresh()->api_token_hash)->not->toBe(IntegrationClient::hashApiToken('old-token'));

    $this->actingAs($admin)
        ->post(route('admin.integrations.webhook-secret.regenerate', $client))
        ->assertRedirect()
        ->assertSessionHas('success', 'integration_client_webhook_secret_regenerated')
        ->assertSessionHas('flash_data.integration_credentials.webhook_signing_secret');

    expect($client->fresh()->webhook_signing_secret)->not->toBe('old-secret');
});

it('lets admins manually run an integration healthcheck', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $client = IntegrationClient::factory()->create([
        'healthcheck_url' => 'https://bot.fullparty.test/health',
    ]);

    Http::fake([
        'https://bot.fullparty.test/health' => Http::response([], 204),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.integrations.healthcheck.run', $client))
        ->assertRedirect()
        ->assertSessionHas('success', 'integration_client_healthcheck_ran');

    $check = IntegrationClientHealthCheck::query()->sole();

    expect($check->integration_client_id)->toBe($client->id)
        ->and($check->status)->toBe(IntegrationClientHealthCheck::STATUS_HEALTHY)
        ->and($client->fresh()->last_healthcheck_ok_at)->not->toBeNull();
});
