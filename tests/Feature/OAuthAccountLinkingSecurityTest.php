<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

function fakeSecurityOAuthProvider(string $provider, string $email): void
{
    $identity = (new SocialiteUser)->map([
        'id' => 'security-provider-user', 'name' => 'Security Test', 'email' => $email,
    ])->setRaw([
        'verified' => true, 'email_verified' => true,
        'user' => ['email_verified' => true], 'characters' => [],
    ])->setToken('security-test-token');
    $driver = Mockery::mock();
    if ($provider === 'xivauth') {
        $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
    }
    $driver->shouldReceive('user')->once()->andReturn($identity);
    Socialite::shouldReceive('driver')->once()->with($provider)->andReturn($driver);
}

it('does not merge a provider into an existing email account', function (string $provider, bool $verified) {
    $user = User::factory()->create([
        'email' => 'existing@example.test', 'email_verified_at' => $verified ? now() : null,
    ]);
    $password = $user->password;
    fakeSecurityOAuthProvider($provider, 'EXISTING@example.test');

    $this->get(route($provider.'.callback'))
        ->assertRedirect(route('social-link.show', session('social_link.token')))
        ->assertSessionHasNoErrors();

    $this->assertGuest();
    expect(SocialAccount::count())->toBe(0)
        ->and($user->fresh()->hasVerifiedEmail())->toBe($verified)
        ->and($user->fresh()->password)->toBe($password);
})->with(['google', 'discord', 'xivauth'])->with([false, true]);

it('requires a verified local account before adding a provider', function (string $provider, bool $matchingEmail) {
    $user = User::factory()->unverified()->create(['email' => 'unowned@example.test']);
    fakeSecurityOAuthProvider($provider, $matchingEmail ? $user->email : 'owned@example.test');

    $this->actingAs($user)->get(route($provider.'.callback'))->assertRedirect(route('settings'))
        ->assertSessionHasErrors(['email' => __('auth.social_link_requires_verified_account')]);

    expect($user->fresh()->email_verified_at)->toBeNull()
        ->and($user->fresh()->email)->toBe('unowned@example.test')
        ->and(SocialAccount::count())->toBe(0);
})->with(['google', 'discord', 'xivauth'])->with([false, true]);

it('allows explicit linking without changing the verified local email', function (string $provider) {
    $user = User::factory()->create(['email' => 'local@example.test']);
    $verifiedAt = $user->email_verified_at->toIso8601String();
    fakeSecurityOAuthProvider($provider, 'provider@example.test');

    $this->actingAs($user)->get(route($provider.'.callback'))->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => $provider]);
    expect($user->fresh()->email)->toBe('local@example.test')
        ->and($user->fresh()->email_verified_at->toIso8601String())->toBe($verifiedAt);
})->with(['google', 'discord', 'xivauth']);

it('preserves sign in through an already linked provider', function (string $provider) {
    $user = User::factory()->create();
    $user->socialAccounts()->create([
        'provider' => $provider, 'provider_user_id' => 'security-provider-user',
    ]);
    fakeSecurityOAuthProvider($provider, 'provider@example.test');

    $this->get(route($provider.'.callback'))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
})->with(['google', 'discord', 'xivauth']);

it('does not switch accounts while linking an identity owned by someone else', function (string $provider) {
    $owner = User::factory()->create();
    $current = User::factory()->create();
    $account = $owner->socialAccounts()->create([
        'provider' => $provider, 'provider_user_id' => 'security-provider-user',
        'access_token' => 'unchanged-token',
    ]);
    fakeSecurityOAuthProvider($provider, 'provider@example.test');

    $this->actingAs($current)->get(route($provider.'.callback'))->assertRedirect(route('settings'))
        ->assertSessionHasErrors(['email' => __('auth.social_account_already_linked')]);

    $this->assertAuthenticatedAs($current);
    expect($account->fresh()->access_token)->toBe('unchanged-token');
})->with(['google', 'discord', 'xivauth']);

it('still registers a new account with a verified provider email', function (string $provider) {
    fakeSecurityOAuthProvider($provider, 'NEW@example.test');
    $this->get(route($provider.'.callback'))->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
    $user = User::where('email', 'new@example.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->hasVerifiedEmail())->toBeTrue()->and($user->password)->toBeNull();
})->with(['google', 'discord', 'xivauth']);
