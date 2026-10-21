<?php

use App\DTOs\LodestoneCharacterData;
use App\DTOs\LodestoneCharacterSearchResult;
use App\Exceptions\LodestoneFetchException;
use App\Models\ActivityApplication;
use App\Models\Character;
use App\Models\User;
use App\Services\Characters\CharacterProfileRefreshService;
use App\Services\Lodestone\LodestoneCharacterSearchService;
use App\Services\Lodestone\LodestoneHttpClient;
use App\Services\Lodestone\LodestoneScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance('env', 'local');
    Queue::fake();
    Http::preventStrayRequests();
    Cache::flush();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    // Local preview routes need local mode, so the usual testing-only CSRF bypass is off.
    $this->withSession(['_token' => 'onboarding-test-csrf'])->withHeader('X-CSRF-TOKEN', 'onboarding-test-csrf');
});

function onboardingProfile(): LodestoneCharacterData
{
    return new LodestoneCharacterData(
        lodestoneId: '12345678', profileUrl: 'https://na.finalfantasyxiv.com/lodestone/character/12345678/',
        classJobUrl: 'https://na.finalfantasyxiv.com/lodestone/character/12345678/class_job/',
        name: 'Test Adventurer', world: 'Lich', dataCenter: 'Light', avatarUrl: 'https://example.test/avatar.png', bio: '', extraData: [],
    );
}

function onboardingXivCharacter(array $overrides = []): array
{
    return array_replace([
        'lodestone_id' => '12345678', 'name' => 'Test Adventurer', 'home_world' => 'Lich',
        'data_center' => 'Light', 'avatar_url' => null, 'verified_at' => '2026-01-01T00:00:00Z',
    ], $overrides);
}

function linkOnboardingXivAuth(User $user): void
{
    $user->socialAccounts()->create([
        'provider' => 'xivauth', 'provider_user_id' => 'xiv-test-user',
        'access_token' => 'test-token', 'expires_at' => now()->addHour(),
    ]);
}

it('groups the home world picker by datacenter without losing available worlds', function () {
    $this->get(route('characters.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('V2/Characters/Create')
        ->where('worldGroups', function ($groups) {
            $groups = collect($groups);
            expect($groups->pluck('datacenter')->all())->toContain('Light', 'Chaos')
                ->and(collect($groups->firstWhere('datacenter', 'Light')['worlds'])->pluck('value')->all())
                ->toContain('Odin', 'Lich', 'Zodiark')->not->toContain('Moogle', 'Phantom')
                ->and(collect($groups->firstWhere('datacenter', 'Chaos')['worlds'])->pluck('value')->all())
                ->toContain('Moogle', 'Phantom')
                ->and($groups->pluck('worlds')->flatten(1)->pluck('value')->sort()->values()->all())
                ->toBe(collect(config('lodestone_worlds.values'))->sort()->values()->all());

            return true;
        }));
    Http::assertNothingSent();
});

it('searches by name without a world and returns a safe selection payload', function () {
    $this->mock(LodestoneCharacterSearchService::class, function ($mock) {
        $mock->shouldReceive('availableWorlds')->andReturn(['Lich']);
        $mock->shouldReceive('search')->once()->with('Test Adventurer', '', PHP_INT_MAX)->andReturn([
            new LodestoneCharacterSearchResult('12345678', 'Test Adventurer', 'Lich', 'Light', null, 'https://example.test/profile'),
        ]);
    });
    $this->getJson(route('characters.onboarding.search', ['name' => 'Test Adventurer']))
        ->assertOk()->assertJsonPath('characters.0.lodestone_id', '12345678');
    expect(Character::count())->toBe(0);
});

it('excludes already linked characters from search in every verification state', function (string $state) {
    $character = Character::factory()->for($this->user)->create([
        'lodestone_id' => '12345678',
        'verified_at' => $state === 'unverified' ? null : now(),
        'claimed_at' => $state === 'claimed' ? now() : null,
    ]);
    $this->mock(LodestoneCharacterSearchService::class, function ($mock) {
        $mock->shouldReceive('availableWorlds')->andReturn(['Lich']);
        $mock->shouldReceive('search')->twice()->with('Test Adventurer', '', PHP_INT_MAX)->andReturn([
            new LodestoneCharacterSearchResult('12345678', 'Test Adventurer', 'Lich', 'Light', null, 'https://example.test/profile'),
            new LodestoneCharacterSearchResult('87654321', 'Test Adventurer', 'Odin', 'Light', null, 'https://example.test/other'),
        ]);
    });
    $url = route('characters.onboarding.search', ['name' => 'Test Adventurer']);
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'characters')
        ->assertJsonPath('characters.0.lodestone_id', '87654321');

    Character::factory()->for($this->user)->unverified()->create(['lodestone_id' => '87654321']);
    $this->getJson($url)->assertOk()->assertExactJson(['characters' => []]);
    expect($character->fresh()->user_id)->toBe($this->user->id);
    Http::assertNothingSent();
})->with(['unverified', 'verified', 'claimed']);

it('filters linked characters per account without changing the shared cached search results', function () {
    $other = User::factory()->create();
    $character = Character::factory()->for($this->user)->unverified()->create(['lodestone_id' => '12345678']);
    $this->mock(LodestoneHttpClient::class)->shouldReceive('fetch')->once()->andReturn(<<<'HTML'
        <html><body>
            <a class="entry__link" href="/lodestone/character/12345678/">
                <p class="entry__name">Test Adventurer</p>
                <p class="entry__world">Lich [Light]</p>
            </a>
        </body></html>
        HTML);
    $url = route('characters.onboarding.search', ['name' => 'Test Adventurer', 'world' => 'Lich']);

    $this->getJson($url)->assertOk()->assertExactJson(['characters' => []]);
    $this->actingAs($other)->getJson($url)->assertOk()->assertJsonCount(1, 'characters')
        ->assertJsonPath('characters.0.lodestone_id', '12345678');

    $character->update(['user_id' => null]);
    $this->actingAs($this->user)->getJson($url)->assertOk()->assertJsonCount(1, 'characters')
        ->assertJsonPath('characters.0.lodestone_id', '12345678');
    Http::assertNothingSent();
});

it('applies the search result limit after removing linked characters and preserves result order', function () {
    $results = collect(range(1, 25))->map(fn (int $index) => new LodestoneCharacterSearchResult(
        (string) (12345600 + $index), 'Test Adventurer', 'Lich', 'Light', null, 'https://example.test/profile',
    ));
    foreach ($results->take(3) as $result) {
        Character::factory()->for($this->user)->create(['lodestone_id' => $result->lodestoneId]);
    }
    $this->mock(LodestoneCharacterSearchService::class, function ($mock) use ($results) {
        $mock->shouldReceive('availableWorlds')->andReturn(['Lich']);
        $mock->shouldReceive('search')->once()->with('Test Adventurer', '', PHP_INT_MAX)->andReturn($results->all());
    });
    $response = $this->getJson(route('characters.onboarding.search', ['name' => 'Test Adventurer']))
        ->assertOk()->assertJsonCount(20, 'characters');
    expect(array_column($response->json('characters'), 'lodestone_id'))
        ->toBe(array_map(fn (int $index) => (string) (12345600 + $index), range(4, 23)));
    Http::assertNothingSent();
});

it('adds a searched character as unverified using authoritative profile data and is idempotent', function () {
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->with('12345678')->andReturn(onboardingProfile());
    $payload = ['method' => 'lodestone', 'lodestone_id' => '12345678', 'name' => 'Forged', 'claimed_at' => now()->toIso8601String()];
    $first = $this->postJson(route('characters.onboarding.store'), $payload)->assertOk()
        ->assertJsonPath('character.name', 'Test Adventurer')->assertJsonPath('character.status', 'unverified');
    $this->postJson(route('characters.onboarding.store'), $payload)->assertOk()
        ->assertJsonPath('character.id', $first->json('character.id'));
    expect(Character::count())->toBe(1);
    $character = Character::sole();
    expect($character->user_id)->toBe($this->user->id)
        ->and($character->verified_at)->toBeNull()->and($character->claimed_at)->toBeNull()
        ->and($character->token)->toBeNull()->and((bool) $character->is_primary)->toBeFalse();
    $this->assertDatabaseCount('notification_events', 1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'character.added', 'scope_id' => $character->id]);
});

it('does not downgrade existing characters when added through search', function () {
    $character = Character::factory()->for($this->user)->primary()->create(['claimed_at' => now()]);
    $this->postJson(route('characters.onboarding.store'), ['method' => 'lodestone', 'lodestone_id' => $character->lodestone_id])
        ->assertOk()->assertJsonPath('character.status', 'claimed');
    expect($character->fresh()->isClaimed())->toBeTrue()->and((bool) $character->fresh()->is_primary)->toBeTrue();
});

it('routes searched characters on another account to the appropriate conflict flow without transferring them', function (?string $state) {
    $character = Character::factory()->unverified()->create([
        'verified_at' => $state !== null ? now() : null, 'claimed_at' => $state === 'claimed' ? now() : null,
    ]);
    $owner = $character->user_id;
    $this->postJson(route('characters.onboarding.store'), ['method' => 'lodestone', 'lodestone_id' => $character->lodestone_id])
        ->assertOk()->assertJsonPath('outcome', $state === 'claimed' ? 'already_claimed' : 'claim_required')
        ->assertJsonPath('transferred', false);
    expect($character->fresh()->user_id)->toBe($owner);
    Http::assertNothingSent();
})->with([null, 'verified', 'claimed']);

it('does not claim guest applications or verify a provisional character through search', function () {
    $character = Character::factory()->provisional()->create(['lodestone_id' => '12345678']);
    $application = ActivityApplication::factory()->guest()->create([
        'selected_character_id' => $character->id, 'applicant_lodestone_id' => '12345678',
    ]);
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->andReturn(onboardingProfile());
    $this->postJson(route('characters.onboarding.store'), ['method' => 'lodestone', 'lodestone_id' => '12345678'])
        ->assertOk()->assertJsonPath('character.id', $character->id)->assertJsonPath('character.status', 'unverified');
    expect($application->fresh()->user_id)->toBeNull()
        ->and($character->fresh()->verified_at)->toBeNull();
});

it('enforces character quotas and never creates an extra character', function () {
    config(['quotas.mode' => 'enforce', 'quotas.limits' => array_replace(config('quotas.limits'), ['characters.total' => 1])]);
    Character::factory()->for($this->user)->create();
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->andReturn(onboardingProfile());
    $this->postJson(route('characters.onboarding.store'), ['method' => 'lodestone', 'lodestone_id' => '12345678'])
        ->assertUnprocessable()->assertJsonValidationErrors('quota');
    expect(Character::count())->toBe(1);
});

it('only lists verified xivauth characters without exposing provider secrets', function () {
    linkOnboardingXivAuth($this->user);
    Http::fake(['xivauth.net/api/v1/characters' => Http::response([
        onboardingXivCharacter(['verification_key' => 'secret', 'persistent_key' => 'private']),
        onboardingXivCharacter(['lodestone_id' => '87654321', 'verified_at' => null]),
    ])]);
    $this->getJson(route('characters.onboarding.xivauth'))->assertOk()->assertJsonCount(1, 'characters')
        ->assertJsonPath('characters.0.lodestone_id', '12345678')
        ->assertJsonMissingPath('characters.0.verification_key')->assertJsonMissingPath('characters.0.persistent_key');
});

it('verifies an xivauth selection with the provider instead of trusting browser metadata', function () {
    linkOnboardingXivAuth($this->user);
    Http::fake(['xivauth.net/api/v1/characters' => Http::response([onboardingXivCharacter()])]);
    $this->postJson(route('characters.onboarding.store'), [
        'method' => 'xivauth', 'lodestone_id' => '12345678', 'name' => 'Forged', 'world' => 'Phoenix',
    ])->assertOk()->assertJsonPath('character.name', 'Test Adventurer')->assertJsonPath('character.status', 'claimed');
    expect(Character::sole()->claimed_at)->not->toBeNull();
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('refuses unverified or unshared xiv characters', function () {
    linkOnboardingXivAuth($this->user);
    Http::fake(['xivauth.net/api/v1/characters' => Http::response([onboardingXivCharacter(['verified_at' => null])])]);
    $this->postJson(route('characters.onboarding.store'), ['method' => 'xivauth', 'lodestone_id' => '12345678'])
        ->assertUnprocessable()->assertJsonPath('errors.onboarding.0', 'xivauth_character_unavailable');
    expect(Character::count())->toBe(0);
});

it('requires a linked xiv account and handles provider outages', function (bool $linked) {
    if ($linked) {
        linkOnboardingXivAuth($this->user);
        Http::fake(['xivauth.net/api/v1/characters' => Http::response(['error' => 'provider detail'], 503)]);
    }
    $this->getJson(route('characters.onboarding.xivauth'))->assertUnprocessable()
        ->assertJsonPath('errors.onboarding.0', $linked ? 'xivauth_unavailable' : 'xivauth_reconnect');
})->with([true, false]);

it('reuses profile refresh and exposes partial data without leaking external diagnostics', function () {
    $character = Character::factory()->for($this->user)->unverified()->create();
    $this->mock(CharacterProfileRefreshService::class)->shouldReceive('refresh')->once()
        ->withArgs(fn (Character $actual, bool $ignoreCache) => $actual->is($character) && $ignoreCache)
        ->andReturn(['fflogs_error' => ['message' => 'private diagnostic']]);
    $this->postJson(route('characters.onboarding.refresh', $character))->assertOk()
        ->assertJsonPath('partial', true)->assertJsonMissingPath('fflogs_error');
});

it('preserves added characters when refresh fails and supports retry', function () {
    $character = Character::factory()->for($this->user)->unverified()->create();
    $this->mock(CharacterProfileRefreshService::class)->shouldReceive('refresh')->once()->andThrow(LodestoneFetchException::timeout('mock'));
    $this->postJson(route('characters.onboarding.refresh', $character))->assertUnprocessable()
        ->assertJsonPath('errors.onboarding.0', 'lodestone_unavailable');
    expect($character->fresh()->user_id)->toBe($this->user->id);
    $this->mock(CharacterProfileRefreshService::class)->shouldReceive('refresh')->once()->andReturn(['fflogs_error' => null]);
    $this->postJson(route('characters.onboarding.refresh', $character))->assertOk()->assertJsonPath('partial', false);
    expect(Character::count())->toBe(1);
});

it('restricts refresh and the claim placeholder to the character owner', function () {
    $other = Character::factory()->create();
    $this->postJson(route('characters.onboarding.refresh', $other))->assertNotFound();
    $this->get(route('characters.claim', $other))->assertNotFound();
    $owned = Character::factory()->for($this->user)->unverified()->create();
    $this->get(route('characters.claim', $owned))->assertOk()->assertInertia(fn (Assert $page) => $page->component('V2/Characters/Claim'));
});

it('protects onboarding endpoints from guests and production access', function () {
    app()->instance('env', 'production');
    $this->getJson(route('characters.onboarding.search', ['name' => 'Tester']))->assertNotFound();
    $this->getJson(route('characters.onboarding.xivauth'))->assertNotFound();
    $this->postJson(route('characters.onboarding.store'), ['method' => 'lodestone', 'lodestone_id' => '12345678'])->assertNotFound();
    app()->instance('env', 'local');
    auth()->logout();
    $this->getJson(route('characters.onboarding.search', ['name' => 'Tester']))->assertUnauthorized();
    $this->postJson(route('characters.onboarding.store'), ['method' => 'lodestone', 'lodestone_id' => '12345678'])->assertUnauthorized();
});

it('returns to the xiv picker after linking without importing any characters yet', function () {
    $providerUser = (new SocialiteUser)->map(['id' => 'new-xiv', 'name' => 'Test', 'email' => $this->user->email])
        ->setRaw(['email_verified' => true, 'characters' => [onboardingXivCharacter()]])->setToken('test-token');
    $driver = Mockery::mock();
    $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
    $driver->shouldReceive('user')->once()->andReturn($providerUser);
    Socialite::shouldReceive('driver')->with('xivauth')->once()->andReturn($driver);
    $this->withSession(['character_onboarding.oauth' => [
        'user_id' => $this->user->id, 'state' => 'oauth-state', 'locale' => 'en',
    ]])->get(route('xivauth.callback', ['state' => 'oauth-state']))
        ->assertRedirect(route('characters.create', ['locale' => 'en', 'import' => 'xivauth']))
        ->assertSessionMissing('character_onboarding.oauth');
    $this->assertDatabaseHas('social_accounts', ['user_id' => $this->user->id, 'provider' => 'xivauth']);
    expect(Character::count())->toBe(0);
});

it('retains socialite state validation for the onboarding callback', function () {
    $driver = Mockery::mock();
    $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
    $driver->shouldReceive('user')->once()->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('xivauth')->once()->andReturn($driver);
    $this->withSession(['character_onboarding.oauth' => [
        'user_id' => $this->user->id, 'state' => 'oauth-state', 'locale' => 'en',
    ]])->get(route('xivauth.callback', ['state' => 'oauth-state']))->assertSessionHasErrors('error');
    expect(Character::count())->toBe(0);
    $this->assertDatabaseCount('social_accounts', 0);
});

it('rejects onboarding callbacks with a changed session account or state', function (string $mismatch) {
    Socialite::shouldReceive('driver')->never();
    $this->withSession(['character_onboarding.oauth' => [
        'user_id' => $mismatch === 'user' ? $this->user->id + 1 : $this->user->id,
        'state' => 'oauth-state', 'locale' => 'en',
    ]])->get(route('xivauth.callback', ['state' => $mismatch === 'state' ? 'wrong' : 'oauth-state']))
        ->assertSessionHasErrors('error');
    $this->assertDatabaseCount('social_accounts', 0);
})->with(['user', 'state']);

it('binds the onboarding return to oauth state and the current user', function () {
    $driver = Mockery::mock();
    $driver->shouldReceive('enablePKCE', 'withEmailScope', 'withCharactersScope')->andReturnSelf();
    $driver->shouldReceive('redirect')->once()->andReturnUsing(function () {
        session()->put('state', 'generated-state');

        return redirect('https://xivauth.net/oauth/authorize');
    });
    Socialite::shouldReceive('driver')->with('xivauth')->once()->andReturn($driver);
    $this->get(route('xivauth.redirect', ['onboarding' => 1, 'return_url' => 'https://untrusted.example']))
        ->assertRedirect('https://xivauth.net/oauth/authorize')
        ->assertSessionHas('character_onboarding.oauth.user_id', $this->user->id)
        ->assertSessionHas('character_onboarding.oauth.state', 'generated-state')
        ->assertSessionMissing('character_onboarding.oauth.return_url');
});

it('handles oauth cancellation without linking or importing', function () {
    Socialite::shouldReceive('driver')->never();
    $this->withSession(['character_onboarding.oauth' => [
        'user_id' => $this->user->id, 'state' => 'oauth-state', 'locale' => 'en',
    ]])->get(route('xivauth.callback', ['state' => 'oauth-state', 'error' => 'access_denied']))
        ->assertRedirect(route('characters.create', ['locale' => 'en', 'import' => 'xivauth']))
        ->assertSessionHasErrors('onboarding');
    $this->assertDatabaseCount('social_accounts', 0);
    expect(Character::count())->toBe(0);
});

it('keeps csrf protection on the new mutations', function () {
    $this->withHeader('X-CSRF-TOKEN', 'wrong-token')
        ->postJson(route('characters.onboarding.store'), ['method' => 'lodestone', 'lodestone_id' => '12345678'])
        ->assertStatus(419);
    expect(Character::count())->toBe(0);
});

it('refreshes expired xiv credentials before fetching the verified character list', function () {
    linkOnboardingXivAuth($this->user);
    $account = $this->user->socialAccounts()->sole();
    $account->update(['expires_at' => now()->subMinute(), 'refresh_token' => 'old-refresh']);
    Http::fake([
        'xivauth.net/oauth/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]),
        'xivauth.net/api/v1/characters' => Http::response([onboardingXivCharacter()]),
    ]);
    $this->getJson(route('characters.onboarding.xivauth'))->assertOk()->assertJsonCount(1, 'characters');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/characters') && $request->hasHeader('Authorization', 'Bearer new-access'));
    expect($account->fresh()->refresh_token)->toBe('new-refresh');
});

it('offers reconnection when the xiv refresh token is rejected without exposing the response', function () {
    linkOnboardingXivAuth($this->user);
    $this->user->socialAccounts()->sole()->update(['expires_at' => now()->subMinute(), 'refresh_token' => 'old-refresh']);
    Http::fake(['xivauth.net/oauth/token' => Http::response(['error' => 'invalid_grant', 'detail' => 'private provider info'], 400)]);
    $this->getJson(route('characters.onboarding.xivauth'))->assertUnprocessable()
        ->assertJsonPath('errors.onboarding.0', 'xivauth_reconnect')->assertDontSee('private provider info');
    Http::assertSentCount(1);
});

it('returns to onboarding when oauth token exchange fails', function () {
    $driver = Mockery::mock();
    $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
    $driver->shouldReceive('user')->once()->andThrow(new RuntimeException('provider timeout'));
    Socialite::shouldReceive('driver')->with('xivauth')->once()->andReturn($driver);
    $this->withSession(['character_onboarding.oauth' => [
        'user_id' => $this->user->id, 'state' => 'oauth-state', 'locale' => 'en',
    ]])->get(route('xivauth.callback', ['state' => 'oauth-state']))
        ->assertRedirect(route('characters.create', ['locale' => 'en', 'import' => 'xivauth']))
        ->assertSessionHasErrors(['onboarding' => 'xivauth_unavailable']);
    $this->assertDatabaseCount('social_accounts', 0);
});
