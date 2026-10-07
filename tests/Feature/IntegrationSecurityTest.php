<?php

use App\Models\AuditLog;
use App\Models\DiscordUserIntegration;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Integrations\IntegrationHealthcheckService;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use App\Support\Integrations\IntegrationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->freezeTime();
    $this->member = User::factory()->create();
    $this->link = DiscordUserIntegration::create(['user_id' => $this->member->id, 'discord_user_id' => '123456789012345678', 'user_app_installed_at' => now()]);
    $this->token = IntegrationClient::makePlainApiToken();
    $this->client = IntegrationClient::factory()->withApiToken($this->token)->create(['scopes' => [IntegrationPermissions::MEMBERS_READ, IntegrationPermissions::MEMBERS_WRITE]]);
    $this->headers = ['Authorization' => 'Bearer '.$this->token, 'X-FullParty-Discord-User-Id' => $this->link->discord_user_id];
});

it('limits invalid credentials before database authentication', function () {
    for ($i = 0; $i < 360; $i++) {
        $this->getJson('/api/integrations/v1/me', ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    }
    DB::enableQueryLog();
    $this->getJson('/api/integrations/v1/me', ['Authorization' => 'Bearer invalid'])->assertStatus(429)->assertHeader('Retry-After');
    expect(collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'integration_clients')))->toBeEmpty();
});

it('charges failed actor and scope checks to the shared client quota before resolving an actor', function () {
    for ($i = 0; $i < 90; $i++) {
        $this->getJson('/api/integrations/v1/me', [...$this->headers, 'X-FullParty-Discord-User-Id' => '999'])->assertNotFound();
        $this->getJson('/api/integrations/v1/bot/runs/1', $this->headers)->assertForbidden();
    }
    DB::enableQueryLog();
    $this->getJson('/api/integrations/v1/me', $this->headers)->assertStatus(429);
    $queries = collect(DB::getQueryLog())->pluck('query');
    expect($queries->filter(fn ($query) => str_contains($query, 'discord_user_integrations')))->toBeEmpty()
        ->and($queries->filter(fn ($query) => str_starts_with($query, 'update ')))->toBeEmpty()
        ->and($this->client->fresh()->last_api_used_at)->toBeNull();
});

it('coalesces admitted usage updates and never writes usage on throttled requests', function () {
    DB::enableQueryLog();
    for ($i = 0; $i < 180; $i++) {
        $this->getJson('/api/integrations/v1/me', $this->headers)->assertOk();
    }
    expect(collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with($query['query'], 'update "integration_clients"')))->toHaveCount(1);
    DB::flushQueryLog();
    $this->travel(1)->seconds();
    $this->getJson('/api/integrations/v1/me', $this->headers)->assertStatus(429);
    expect(collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with($query['query'], 'update ')))->toBeEmpty();
    DB::flushQueryLog();
    $this->travel(61)->seconds();
    $this->getJson('/api/integrations/v1/me', $this->headers)->assertOk();
    expect(collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with($query['query'], 'update "integration_clients"')))->toHaveCount(1);
});

it('limits avatar and background uploads together but leaves text-only profile edits available', function () {
    for ($i = 0; $i < 5; $i++) {
        // Invalid files still consume quota, before image validation/decoding.
        $this->post('/api/integrations/v1/me/avatar', ['profile_picture' => UploadedFile::fake()->create('invalid.txt', 1)], $this->headers)->assertUnprocessable();
    }
    $this->post('/api/integrations/v1/me/profile', ['background_image' => UploadedFile::fake()->create('invalid.txt', 1)], $this->headers)->assertStatus(429);
    $this->postJson('/api/integrations/v1/me/profile', ['description' => 'A profile update'], $this->headers)->assertOk();
});

it('audits permission changes and credential rotation without recording credential values', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $data = [
        'name' => 'Audited client', 'type' => IntegrationClient::TYPE_DISCORD_BOT, 'status' => IntegrationClient::STATUS_ACTIVE,
        'scopes' => [IntegrationPermissions::MEMBERS_READ], 'allowed_events' => [],
        'outbound_events_url' => 'https://example.test/events?secret=never-log-this', 'healthcheck_url' => null,
    ];
    $this->actingAs($admin)->post(route('admin.integrations.store'), $data)->assertRedirect();
    $created = IntegrationClient::where('name', 'Audited client')->sole();
    $this->put(route('admin.integrations.update', $created), [...$data, 'status' => IntegrationClient::STATUS_REVOKED, 'scopes' => []])->assertRedirect();
    $this->post(route('admin.integrations.api-token.regenerate', $created))->assertRedirect();
    $this->post(route('admin.integrations.webhook-secret.regenerate', $created))->assertRedirect();
    $logs = AuditLog::where('subject_type', IntegrationClient::class)->where('subject_id', $created->id)->orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe([
        'admin.integration_client.created', 'admin.integration_client.updated', 'admin.integration_client.api_token_rotated', 'admin.integration_client.webhook_secret_rotated',
    ])->and($logs->pluck('actor_user_id')->unique()->all())->toBe([$admin->id]);
    expect($logs[1]->metadata['changes']['scopes']['old'])->toBe([IntegrationPermissions::MEMBERS_READ])
        ->and($logs[1]->metadata['changes']['scopes']['new'])->toBe([]);
    $serialized = $logs->toJson();
    expect($serialized)->not->toContain('never-log-this')->not->toContain($created->fresh()->api_token_hash)->not->toContain($created->fresh()->webhook_signing_secret);
});

it('requires HTTPS when configuring production endpoints but permits local HTTP', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $data = ['name' => 'Local bot', 'type' => IntegrationClient::TYPE_DISCORD_BOT, 'status' => IntegrationClient::STATUS_ACTIVE, 'scopes' => [], 'allowed_events' => [], 'outbound_events_url' => 'http://localhost:9000/events', 'healthcheck_url' => 'http://localhost:9000/health'];
    $this->withSession(['_token' => 'integration-test-csrf'])->withHeaders(['X-CSRF-TOKEN' => 'integration-test-csrf']);
    $this->app['env'] = 'production';
    $this->actingAs($admin)->postJson(route('admin.integrations.store'), $data)->assertUnprocessable()->assertJsonValidationErrors(['outbound_events_url', 'healthcheck_url']);
    $this->app['env'] = 'local';
    $this->post(route('admin.integrations.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
});

it('blocks previously saved insecure production destinations before HTTP requests and records failures', function () {
    $this->app['env'] = 'production';
    $this->client->update(['outbound_events_url' => 'http://example.test/events', 'healthcheck_url' => 'http://example.test/health', 'allowed_events' => [IntegrationClient::EVENT_DISCORD_USER_APP_INSTALLED]]);
    $delivery = app(IntegrationWebhookDispatcher::class)->dispatchDiscordBotEvent(IntegrationClient::EVENT_DISCORD_USER_APP_INSTALLED, ['test' => true]);
    expect($delivery['failed'])->toBe(1)->and($this->client->fresh()->last_event_error)->toContain('HTTPS');
    app(IntegrationHealthcheckService::class)->check($this->client->fresh());
    expect($this->client->fresh()->last_healthcheck_error)->toContain('HTTPS');
    Http::assertNothingSent();
});

it('refuses webhook redirects rather than forwarding signed payloads elsewhere', function () {
    $this->client->update(['outbound_events_url' => 'https://example.test/events', 'allowed_events' => [IntegrationClient::EVENT_DISCORD_USER_APP_INSTALLED]]);
    Http::fake(['https://example.test/events' => Http::response('', 307, ['Location' => 'http://elsewhere.test/events'])]);
    $delivery = app(IntegrationWebhookDispatcher::class)->dispatchDiscordBotEvent(IntegrationClient::EVENT_DISCORD_USER_APP_INSTALLED, ['test' => true]);
    expect($delivery['failed'])->toBe(1)->and($this->client->fresh()->last_event_error)->toContain('307');
    Http::assertSentCount(1);
});

it('records redirected healthchecks as unhealthy even when their body claims success', function () {
    $this->client->update(['healthcheck_url' => 'https://example.test/health']);
    Http::fake(['https://example.test/health' => Http::response(['status' => 'healthy'], 307, ['Location' => 'http://elsewhere.test/health'])]);
    app(IntegrationHealthcheckService::class)->check($this->client->fresh());
    expect($this->client->healthChecks()->sole()->status)->toBe('unhealthy')
        ->and($this->client->fresh()->last_healthcheck_failed_at)->not->toBeNull();
    Http::assertSentCount(1);
});
