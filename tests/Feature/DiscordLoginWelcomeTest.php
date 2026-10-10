<?php

use App\Jobs\SendDiscordLoginWelcomeJob;
use App\Models\DiscordUserIntegration;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Auth\DiscordLoginWelcomeService;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Support\OpenApiContract;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

function fakeDiscordWelcomeProvider(string $provider = 'discord', string $email = 'welcome@example.test', bool $verified = true): void
{
    $identity = (new SocialiteUser)->map([
        'id' => '234567890123456789', 'name' => 'Welcome User', 'nickname' => 'welcome',
        'email' => $email, 'avatar' => 'https://cdn.discordapp.com/avatar.png',
    ])->setRaw(['verified' => $verified, 'email_verified' => $verified, 'user' => ['email_verified' => $verified], 'characters' => []])
        ->setToken('private-oauth-token');
    $providerMock = Mockery::mock();
    if ($provider === 'xivauth') {
        $providerMock->shouldReceive('enablePKCE')->once()->andReturnSelf();
    }
    $providerMock->shouldReceive('user')->once()->andReturn($identity);
    Socialite::shouldReceive('driver')->once()->with($provider)->andReturn($providerMock);
}

function discordWelcomeAccount(User $user): void
{
    $user->socialAccounts()->create([
        'provider' => 'discord', 'provider_user_id' => '234567890123456789',
        'provider_name' => 'Welcome User', 'provider_data' => ['nickname' => 'welcome'],
    ]);
}

it('queues one welcome for Discord registration and never repeats it on subsequent logins', function () {
    fakeDiscordWelcomeProvider();
    $this->get(route('discord.callback'))->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->discord_login_welcome_recorded_at)->not->toBeNull()
        ->and($user->toArray())->not->toHaveKey('discord_login_welcome_recorded_at')
        ->and(DiscordUserIntegration::count())->toBe(0);

    Auth::logout();
    fakeDiscordWelcomeProvider();
    $this->get(route('discord.callback'))->assertRedirect(route('dashboard'));

    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, 1);
    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, fn ($job) => $job->userId === $user->id
        && $job->discordUserId === '234567890123456789' && $job->afterCommit === true);
});

it('waits for a real Discord login when an authenticated user only links Discord in settings', function () {
    $user = User::factory()->create();
    fakeDiscordWelcomeProvider(email: $user->email);
    $this->actingAs($user)->get(route('discord.callback'))->assertSessionHasNoErrors();
    Queue::assertNotPushed(SendDiscordLoginWelcomeJob::class);
    expect($user->fresh()->discord_login_welcome_recorded_at)->toBeNull();

    Auth::logout();
    fakeDiscordWelcomeProvider(email: $user->email);
    $this->get(route('discord.callback'))->assertSessionHasNoErrors();
    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, 1);
});

it('does not welcome a Discord-linked user who logs in using another social provider', function (string $provider) {
    $user = User::factory()->create();
    discordWelcomeAccount($user);
    $user->socialAccounts()->create(['provider' => $provider, 'provider_user_id' => '234567890123456789']);
    fakeDiscordWelcomeProvider(provider: $provider, email: $user->email);
    $this->get(route($provider.'.callback'))->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
    Queue::assertNotPushed(SendDiscordLoginWelcomeJob::class);
    expect($user->fresh()->discord_login_welcome_recorded_at)->toBeNull();
})->with(['google', 'xivauth']);

it('does not welcome a Discord-linked user who logs in using a password', function () {
    $user = User::factory()->create();
    discordWelcomeAccount($user);
    $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
    Queue::assertNotPushed(SendDiscordLoginWelcomeJob::class);
    expect($user->fresh()->discord_login_welcome_recorded_at)->toBeNull();
});

it('does not welcome unverified or rejected Discord callbacks', function (string $failure) {
    if ($failure === 'state') {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->once()->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->once()->with('discord')->andReturn($provider);
    } else {
        fakeDiscordWelcomeProvider(verified: false);
    }
    $this->get(route('discord.callback'))->assertSessionHasErrors();
    $this->assertGuest();
    Queue::assertNotPushed(SendDiscordLoginWelcomeJob::class);
})->with(['state', 'email']);

it('waits for successful ownership verification before welcoming a Discord account-linking login', function () {
    $user = User::factory()->create();
    fakeDiscordWelcomeProvider(email: $user->email);
    $this->get(route('discord.callback'))->assertSessionHasNoErrors();
    $token = session('social_link.token');
    $this->assertGuest();
    Queue::assertNotPushed(SendDiscordLoginWelcomeJob::class);

    $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors();
    Queue::assertNotPushed(SendDiscordLoginWelcomeJob::class);

    $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, 1);
    expect($user->fresh()->discord_login_welcome_recorded_at)->not->toBeNull();
});

it('welcomes a first Discord login used to verify another pending social link', function () {
    $user = User::factory()->create();
    discordWelcomeAccount($user);
    fakeDiscordWelcomeProvider(provider: 'google', email: $user->email);
    $this->get(route('google.callback'))->assertSessionHasNoErrors();
    $token = session('social_link.token');

    fakeDiscordWelcomeProvider(email: $user->email);
    $this->withSession(['social_link.oauth' => ['token' => $token, 'provider' => 'discord', 'state' => 'verified-state']])
        ->get(route('discord.callback', ['state' => 'verified-state']))->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, 1);
});

it('claims the welcome once even when called with stale user instances', function () {
    $user = User::factory()->create();
    discordWelcomeAccount($user);
    $staleUser = $user->fresh();
    $service = app(DiscordLoginWelcomeService::class);
    $service->recordFirstLogin($user, '234567890123456789');
    $service->recordFirstLogin($staleUser, '234567890123456789');
    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, 1);
    $this->assertDatabaseCount('pending_discord_login_welcomes', 0);
});

it('keeps login successful and retries a welcome after a queue outage without another login', function () {
    $this->freezeTime();
    $dispatcher = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->once()->with(Mockery::type(SendDiscordLoginWelcomeJob::class))
        ->andThrow(new RuntimeException('Queue unavailable'));

    fakeDiscordWelcomeProvider();
    $this->withSession(['locale' => 'de'])->get(route('discord.callback', ['locale' => 'de']))
        ->assertRedirect(route('dashboard', ['locale' => 'de']))->assertSessionHasNoErrors();
    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->discord_login_welcome_recorded_at)->not->toBeNull();
    $pending = DB::table('pending_discord_login_welcomes')->sole();
    expect($pending->user_id)->toBe($user->id)
        ->and($pending->discord_user_id)->toBe('234567890123456789')
        ->and($pending->locale)->toBe('de');
    Queue::assertNotPushed(SendDiscordLoginWelcomeJob::class);

    Bus::swap($dispatcher);
    $this->artisan('discord:dispatch-pending-welcomes')->expectsOutput('Queued 0 pending Discord welcome(s).')->assertSuccessful();
    $this->travel(6)->minutes();
    $this->artisan('discord:dispatch-pending-welcomes')->expectsOutput('Queued 1 pending Discord welcome(s).')->assertSuccessful();
    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, fn ($job) => $job->userId === $user->id
        && $job->deliveryId === $pending->delivery_id && $job->locale === 'de'
        && $job->loggedInAt === $user->discord_login_welcome_recorded_at->toIso8601String());
    $this->assertDatabaseCount('pending_discord_login_welcomes', 0);

    $this->artisan('discord:dispatch-pending-welcomes')->expectsOutput('Queued 0 pending Discord welcome(s).')->assertSuccessful();
    app(DiscordLoginWelcomeService::class)->recordFirstLogin($user->fresh(), '234567890123456789');
    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, 1);
});

it('retains the same delivery ID if enqueueing succeeds but its acknowledgement is lost', function () {
    $user = User::factory()->create();
    discordWelcomeAccount($user);
    $dispatcher = Bus::getFacadeRoot();
    $acceptedJob = null;
    Bus::partialMock()->shouldReceive('dispatch')->once()->with(Mockery::type(SendDiscordLoginWelcomeJob::class))
        ->andReturnUsing(function ($job) use (&$acceptedJob) {
            $acceptedJob = $job;
            // Another dispatcher cannot claim this welcome while the lease is active.
            expect(app(DiscordLoginWelcomeService::class)->dispatchPending())->toBe(0);
            throw new RuntimeException('Queue acknowledgement lost');
        });

    app(DiscordLoginWelcomeService::class)->recordFirstLogin($user, '234567890123456789');
    expect($acceptedJob)->toBeInstanceOf(SendDiscordLoginWelcomeJob::class);
    Bus::swap($dispatcher);
    $this->travel(6)->minutes();
    $this->artisan('discord:dispatch-pending-welcomes')->assertSuccessful();

    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, fn ($job) => $job->deliveryId === $acceptedJob->deliveryId);
    $this->assertDatabaseCount('pending_discord_login_welcomes', 0);
});

it('recovers from a real database queue insert failure', function () {
    config([
        'database.connections.welcome_queue_outage' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'queue.default' => 'welcome_queue_outage',
        'queue.connections.welcome_queue_outage' => [
            'driver' => 'database', 'connection' => 'welcome_queue_outage', 'table' => 'missing_jobs',
            'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
        ],
    ]);
    Queue::swap(new QueueManager($this->app));
    $user = User::factory()->create();
    discordWelcomeAccount($user);

    app(DiscordLoginWelcomeService::class)->recordFirstLogin($user, '234567890123456789');
    expect($user->fresh()->discord_login_welcome_recorded_at)->not->toBeNull();
    $pending = DB::table('pending_discord_login_welcomes')->sole();

    Queue::fake();
    $this->travel(6)->minutes();
    $this->artisan('discord:dispatch-pending-welcomes')->assertSuccessful();
    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, fn ($job) => $job->deliveryId === $pending->delivery_id);
    $this->assertDatabaseCount('pending_discord_login_welcomes', 0);
});

it('rolls back the welcome claim and pending intent together without enqueueing', function () {
    $user = User::factory()->create();
    discordWelcomeAccount($user);
    DB::beginTransaction();
    try {
        app(DiscordLoginWelcomeService::class)->recordFirstLogin($user, '234567890123456789');
        $this->assertDatabaseCount('pending_discord_login_welcomes', 1);
        Queue::assertNotPushed(SendDiscordLoginWelcomeJob::class);
    } finally {
        DB::rollBack();
    }

    expect($user->fresh()->discord_login_welcome_recorded_at)->toBeNull();
    $this->assertDatabaseCount('pending_discord_login_welcomes', 0);
    app(DiscordLoginWelcomeService::class)->recordFirstLogin($user->fresh(), '234567890123456789');
    Queue::assertPushed(SendDiscordLoginWelcomeJob::class, 1);
});

it('removes a pending welcome when its user is deleted', function () {
    $user = User::factory()->create();
    discordWelcomeAccount($user);
    Bus::partialMock()->shouldReceive('dispatch')->once()->with(Mockery::type(SendDiscordLoginWelcomeJob::class))
        ->andThrow(new RuntimeException('Queue unavailable'));
    app(DiscordLoginWelcomeService::class)->recordFirstLogin($user, '234567890123456789');
    $this->assertDatabaseCount('pending_discord_login_welcomes', 1);

    $user->delete();
    $this->assertDatabaseCount('pending_discord_login_welcomes', 0);
});

it('sends a signed welcome with durable install links and no OAuth secrets', function (bool $installed) {
    $user = User::factory()->create();
    discordWelcomeAccount($user);
    if ($installed) {
        DiscordUserIntegration::create(['user_id' => $user->id, 'discord_user_id' => '234567890123456789', 'user_app_installed_at' => now()]);
    }
    $client = IntegrationClient::factory()->create(['allowed_events' => [IntegrationClient::EVENT_USER_DISCORD_LOGIN]]);
    Http::fake([$client->outbound_events_url => Http::response([], 204)]);
    $job = new SendDiscordLoginWelcomeJob($user->id, '234567890123456789', now()->toIso8601String(), 'de');
    $job->handle(app(IntegrationWebhookDispatcher::class));

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($user, $client, $job, $installed) {
        $document = json_decode(file_get_contents(resource_path('openapi/fullparty.json')), true, flags: JSON_THROW_ON_ERROR);
        OpenApiContract::assertMatches(json_decode($request->body()), $document['webhooks']['user.discord_login']['post']['requestBody']['content']['application/json']['schema'], $document);
        $timestamp = $request->header('X-FullParty-Timestamp')[0];
        expect($request->method())->toBe('POST')
            ->and($request['event'])->toBe('user.discord_login')
            ->and($request['id'])->toBe($job->deliveryId)
            ->and($request['data']['user'])->toBe(['id' => $user->id, 'name' => $user->name])
            ->and($request['data']['discord_user_id'])->toBe('234567890123456789')
            ->and($request['data']['discord_app_install_url'])->toBe(route('discord-app.user.redirect'))
            ->and($request['data']['settings_url'])->toBe(route('settings', ['locale' => 'de']))
            ->and($request['data']['discord_app_installed'])->toBe($installed)
            ->and($request->header('X-FullParty-Signature')[0])->toBe('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $client->webhook_signing_secret))
            ->and($request->body())->not->toContain($user->email, 'access_token', 'refresh_token');

        return true;
    });
})->with([true, false]);

it('retains the delivery ID when retrying a failed bot delivery', function () {
    $user = User::factory()->create();
    discordWelcomeAccount($user);
    $client = IntegrationClient::factory()->create(['allowed_events' => [IntegrationClient::EVENT_USER_DISCORD_LOGIN]]);
    Http::fake([$client->outbound_events_url => Http::sequence()->push([], 503)->push([], 503)->push([], 204)]);
    $job = new SendDiscordLoginWelcomeJob($user->id, '234567890123456789', now()->toIso8601String(), 'en');
    expect(fn () => $job->handle(app(IntegrationWebhookDispatcher::class)))->toThrow(RuntimeException::class, 'Discord login welcome delivery failed.');
    $job->handle(app(IntegrationWebhookDispatcher::class));

    Http::assertSentCount(3);
    foreach (Http::recorded() as [$request]) {
        expect($request['id'])->toBe($job->deliveryId)
            ->and($request->header('X-FullParty-Delivery')[0])->toBe($job->deliveryId);
    }
});

it('does not deliver to paused clients or clients without the welcome capability', function (bool $paused) {
    $user = User::factory()->create();
    discordWelcomeAccount($user);
    IntegrationClient::factory()->create([
        'status' => $paused ? IntegrationClient::STATUS_PAUSED : IntegrationClient::STATUS_ACTIVE,
        'allowed_events' => $paused ? [IntegrationClient::EVENT_USER_DISCORD_LOGIN] : [IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY],
    ]);
    (new SendDiscordLoginWelcomeJob($user->id, '234567890123456789', now()->toIso8601String(), 'en'))
        ->handle(app(IntegrationWebhookDispatcher::class));
    Http::assertNothingSent();
})->with([true, false]);

it('does not deliver after the Discord identity is unlinked or the user is banned', function (bool $banned) {
    $user = User::factory()->create(['banned_at' => $banned ? now() : null]);
    if ($banned) {
        discordWelcomeAccount($user);
    }
    IntegrationClient::factory()->create();
    (new SendDiscordLoginWelcomeJob($user->id, '234567890123456789', now()->toIso8601String(), 'en'))
        ->handle(app(IntegrationWebhookDispatcher::class));
    app(DiscordLoginWelcomeService::class)->recordFirstLogin($user, '234567890123456789');
    Queue::assertNotPushed(SendDiscordLoginWelcomeJob::class);
    Http::assertNothingSent();
})->with([true, false]);

it('excludes existing Discord users during migration and preserves existing capability choices', function () {
    $migration = require database_path('migrations/2026_10_10_000001_add_discord_login_welcome.php');
    $migration->down();
    $discordUser = User::factory()->create();
    discordWelcomeAccount($discordUser);
    $otherUser = User::factory()->create();
    $otherUser->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => '123']);
    $unlinkedUser = User::factory()->create();
    DB::table('audit_logs')->insert([
        'actor_user_id' => $unlinkedUser->id, 'action' => 'user.logged_in', 'severity' => 'info',
        'scope_type' => 'user', 'scope_id' => $unlinkedUser->id, 'message' => 'audit_log.events.user.logged_in',
        'metadata' => json_encode(['provider' => 'discord']), 'created_at' => now()->subDay(),
    ]);
    $accountsClient = IntegrationClient::factory()->create(['allowed_events' => ['discord.user_app.installed', 'discord.notification.delivery']]);
    $otherClient = IntegrationClient::factory()->create(['allowed_events' => ['discord.notification.delivery']]);

    $migration->up();

    expect($discordUser->fresh()->discord_login_welcome_recorded_at)->not->toBeNull()
        ->and($unlinkedUser->fresh()->discord_login_welcome_recorded_at)->not->toBeNull()
        ->and($otherUser->fresh()->discord_login_welcome_recorded_at)->toBeNull()
        ->and($accountsClient->fresh()->allowed_events)->toBe(['discord.user_app.installed', 'discord.notification.delivery', 'user.discord_login'])
        ->and($otherClient->fresh()->allowed_events)->toBe(['discord.notification.delivery']);
    app(DiscordLoginWelcomeService::class)->recordFirstLogin($discordUser->fresh(), '234567890123456789');
    Queue::assertNotPushed(SendDiscordLoginWelcomeJob::class);

    $migration->down();
    expect($accountsClient->fresh()->allowed_events)->toBe(['discord.user_app.installed', 'discord.notification.delivery']);
    $migration->up();
});
