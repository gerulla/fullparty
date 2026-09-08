<?php

use App\Models\PendingSocialLink;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

function fakeHandoffProvider(string $provider, string $email, string $id = 'new-identity'): void
{
    $identity = (new SocialiteUser)->map(['id' => $id, 'name' => 'Provider User', 'email' => $email])
        ->setRaw(['verified' => true, 'email_verified' => true, 'user' => ['email_verified' => true], 'characters' => []])
        ->setToken('secret-access-token');
    $driver = Mockery::mock();
    if ($provider === 'xivauth') {
        $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
    }
    $driver->shouldReceive('user')->once()->andReturn($identity);
    Socialite::shouldReceive('driver')->once()->with($provider)->andReturn($driver);
}

function startSocialHandoff(User $user, string $provider = 'google'): string
{
    fakeHandoffProvider($provider, strtoupper($user->email));
    test()->get(route($provider.'.callback'))->assertSessionHasNoErrors();

    return session('social_link.token');
}

function secondaryHandoffContext(string $token, string $provider = 'discord', string $state = 'verified-state'): array
{
    return ['social_link.oauth' => compact('token', 'provider', 'state')];
}

it('presents a private auth page and encrypts the pending identity', function (string $provider) {
    $user = User::factory()->create();
    $token = startSocialHandoff($user, $provider);
    $this->assertGuest();
    $this->get(route('social-link.show', $token))->assertInertia(fn (Assert $page) => $page
        ->component('auth/LinkSocial')->where('email', $user->email)->where('expired', false)
        ->where('canComplete', false)->missing('identity')->missing('user_id')->missing('linkedProviders'));
    $raw = DB::table('pending_social_links')->first();
    expect($raw->id)->toBe(hash('sha256', $token))
        ->and($raw->payload)->not->toContain('secret-access-token', $user->email, 'new-identity')
        ->and(PendingSocialLink::first()->payload['identity']['attributes']['access_token'])->toBe('secret-access-token')
        ->and(SocialAccount::count())->toBe(0);
})->with(['google', 'discord', 'xivauth']);

it('links only after the correct password and consumes the request once', function (string $provider) {
    $user = User::factory()->create();
    $verifiedAt = $user->email_verified_at->toIso8601String();
    $token = startSocialHandoff($user, $provider);
    $this->withSession(['url.intended' => route('settings')])->post(route('social-link.login', $token), [
        'login' => $user->email, 'password' => 'password', 'remember' => true,
    ])->assertRedirect(route('settings'))->assertSessionHasNoErrors()->assertSessionMissing('social_link');
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => 'new-identity']);
    expect($user->fresh()->email_verified_at->toIso8601String())->toBe($verifiedAt)
        ->and(PendingSocialLink::count())->toBe(0);
    $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'password'])->assertGone();
    expect(SocialAccount::count())->toBe(1);
})->with(['google', 'discord', 'xivauth']);

it('rejects a wrong password or a different accounts valid password', function (bool $otherAccount) {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $token = startSocialHandoff($user);
    $this->from(route('social-link.show', $token))->post(route('social-link.login', $token), [
        'login' => $otherAccount ? $other->email : $user->email,
        'password' => $otherAccount ? 'password' : 'wrong-password',
    ])->assertRedirect(route('social-link.show', $token))->assertSessionHasErrors('login');
    $this->assertGuest();
    expect(SocialAccount::count())->toBe(0)->and(PendingSocialLink::count())->toBe(1);
})->with([true, false]);

it('requires independent local email verification before linking an unverified account', function () {
    $user = User::factory()->unverified()->create();
    $token = startSocialHandoff($user);
    $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('verification.notice'));
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->email_verified_at)->toBeNull()->and(SocialAccount::count())->toBe(0);
    $this->get(route('social-link.show', $token))->assertInertia(fn (Assert $page) => $page
        ->where('verificationRequired', true)->where('canComplete', false));
    $this->post(route('social-link.complete', $token))->assertRedirect(route('verification.notice'));
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), [
        'id' => $user->id, 'hash' => sha1($user->email),
    ]);
    $this->get($url)->assertRedirect(route('social-link.show', $token));
    $this->get(route('social-link.show', $token))->assertInertia(fn (Assert $page) => $page
        ->where('verificationRequired', false)->where('canComplete', true));
    $this->post(route('social-link.complete', $token))->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
    expect(SocialAccount::count())->toBe(1);
});

it('rejects completion without fresh proof or with a changed password', function (bool $withProof) {
    $user = User::factory()->unverified()->create();
    $token = startSocialHandoff($user);
    if ($withProof) {
        $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'password']);
    }
    $user->forceFill(['email_verified_at' => now(), 'password' => 'changed-password'])->save();
    $this->actingAs($user)->post(route('social-link.complete', $token))->assertSessionHasErrors('login');
    expect(SocialAccount::count())->toBe(0);
})->with([true, false]);

it('can prove ownership using an already linked social identity', function (string $provider) {
    $pendingProvider = $provider === 'google' ? 'discord' : 'google';
    $user = User::factory()->create(['password' => null]);
    $existing = $user->socialAccounts()->create(['provider' => $provider, 'provider_user_id' => 'existing-identity']);
    $token = startSocialHandoff($user, $pendingProvider);
    fakeHandoffProvider($provider, 'different-provider-email@example.test', 'existing-identity');
    $this->withSession(secondaryHandoffContext($token, $provider))->get(route($provider.'.callback', ['state' => 'verified-state']))
        ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::count())->toBe(2)->and(PendingSocialLink::count())->toBe(0)
        ->and($existing->fresh()->access_token)->toBe('secret-access-token');
})->with(['google', 'discord', 'xivauth']);

it('rejects unlinked or wrong-owner secondary social identities', function (bool $linkedElsewhere) {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $existing = $linkedElsewhere ? $other->socialAccounts()->create([
        'provider' => 'discord', 'provider_user_id' => 'existing-identity', 'access_token' => 'unchanged',
    ]) : null;
    $token = startSocialHandoff($user);
    fakeHandoffProvider('discord', $user->email, 'existing-identity');
    $this->withSession(secondaryHandoffContext($token))->get(route('discord.callback', ['state' => 'verified-state']))
        ->assertRedirect(route('social-link.show', $token))->assertSessionHasErrors('login');
    $this->assertGuest();
    expect(SocialAccount::count())->toBe($linkedElsewhere ? 1 : 0)
        ->and(User::count())->toBe(2)->and(PendingSocialLink::count())->toBe(1);
    if ($existing) {
        expect($existing->fresh()->access_token)->toBe('unchanged');
    }
})->with([true, false]);

it('requires the secondary callback state and provider to match the linking attempt', function (string $provider, string $state) {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'discord', 'provider_user_id' => 'existing-identity']);
    $token = startSocialHandoff($user);
    fakeHandoffProvider('discord', $user->email, 'existing-identity');
    $this->withSession(secondaryHandoffContext($token, $provider))->get(route('discord.callback', compact('state')))
        ->assertRedirect(route('social-link.show', $token))->assertSessionHasErrors('link');
    $this->assertGuest();
    expect(SocialAccount::count())->toBe(1);
})->with([['google', 'verified-state'], ['discord', 'different-state']]);

it('does not attach pending identities during unrelated ordinary social login', function () {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'discord', 'provider_user_id' => 'existing-identity']);
    $token = startSocialHandoff($user);
    fakeHandoffProvider('discord', $user->email, 'existing-identity');
    $this->get(route('discord.callback'))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
    $this->post(route('social-link.complete', $token))->assertSessionHasErrors('login');
    expect(SocialAccount::count())->toBe(1)->and(PendingSocialLink::count())->toBe(1);
});

it('binds pending requests to their original browser session', function (string $key) {
    $user = User::factory()->create();
    $token = startSocialHandoff($user);
    $this->withSession([$key => str_repeat('x', 64)])->get(route('social-link.show', $token))
        ->assertInertia(fn (Assert $page) => $page->where('expired', true)->where('email', null)->where('provider', null));
    $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'password'])->assertGone();
    expect(SocialAccount::count())->toBe(0);
})->with(['social_link.token', 'social_link.browser']);

it('expires requests and prunes only expired data', function () {
    $user = User::factory()->create();
    $token = startSocialHandoff($user);
    $this->travel(11)->minutes();
    $this->get(route('social-link.show', $token))->assertInertia(fn (Assert $page) => $page->where('expired', true)->where('email', null));
    $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'password'])->assertGone();
    $pending = PendingSocialLink::first()->replicate();
    $pending->id = hash('sha256', 'other-request');
    $pending->expires_at = now()->addMinutes(5);
    $pending->save();
    $this->artisan('model:prune', ['--model' => [PendingSocialLink::class]])->assertSuccessful();
    expect(PendingSocialLink::count())->toBe(1)->and(PendingSocialLink::first()->id)->toBe($pending->id);
    $this->assertGuest();
});

it('cancels both pending linking and outstanding oauth context', function () {
    $user = User::factory()->create();
    $token = startSocialHandoff($user);
    $this->withSession(secondaryHandoffContext($token) + ['state' => 'state', 'code_verifier' => 'pkce-secret'])
        ->delete(route('social-link.cancel', $token))->assertRedirect(route('login'))
        ->assertSessionMissing('social_link')->assertSessionMissing('state')->assertSessionMissing('code_verifier');
    expect(PendingSocialLink::count())->toBe(0);
    $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'password'])->assertGone();
});

it('invalidates the previous request when starting another in the same browser', function () {
    $user = User::factory()->create();
    $old = startSocialHandoff($user);
    $new = startSocialHandoff($user, 'discord');
    expect($new)->not->toBe($old)->and(PendingSocialLink::count())->toBe(1);
    $this->get(route('social-link.show', $old))->assertInertia(fn (Assert $page) => $page->where('expired', true));
    $this->post(route('social-link.login', $new), ['login' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
    expect(SocialAccount::first()->provider)->toBe('discord');
});

it('rejects changes to the target email or an identity claimed since starting', function (bool $claimed) {
    $user = User::factory()->create();
    $token = startSocialHandoff($user);
    if ($claimed) {
        User::factory()->create()->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'new-identity']);
    } else {
        $user->update(['email' => 'changed@example.test']);
    }
    $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'password'])->assertSessionHasErrors($claimed ? 'link' : 'login');
    $this->assertGuest();
    expect($user->socialAccounts()->count())->toBe(0)->and(PendingSocialLink::count())->toBe(1);
})->with([true, false]);

it('does not switch an existing session to the pending account', function () {
    $user = User::factory()->create();
    $token = startSocialHandoff($user);
    $other = User::factory()->create();
    $this->actingAs($other)->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('login');
    $this->assertAuthenticatedAs($other);
    expect(SocialAccount::count())->toBe(0);
});

it('returns to the pending page after a password reset without linking yet', function () {
    $user = User::factory()->create();
    $token = startSocialHandoff($user);
    $resetToken = Password::broker()->createToken($user);
    $this->post(route('password.update'), [
        'token' => $resetToken, 'email' => $user->email,
        'password' => 'Updated-password-123!', 'password_confirmation' => 'Updated-password-123!',
    ])->assertRedirect(route('social-link.show', $token))->assertSessionHasNoErrors();
    $this->assertGuest();
    expect(SocialAccount::count())->toBe(0);
    $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'Updated-password-123!'])
        ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
});

it('throttles password attempts on the linking endpoint', function () {
    $user = User::factory()->create();
    $token = startSocialHandoff($user);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('login');
    }
    $this->post(route('social-link.login', $token), ['login' => $user->email, 'password' => 'wrong'])->assertTooManyRequests();
});

it('ties secondary oauth redirects to the pending request and generated oauth state', function (string $provider) {
    $user = User::factory()->create();
    $token = startSocialHandoff($user, $provider === 'google' ? 'discord' : 'google');
    $driver = Mockery::mock();
    if ($provider === 'xivauth') {
        $driver->shouldReceive('enablePKCE', 'withEmailScope', 'withCharactersScope')->once()->andReturnSelf();
    }
    $driver->shouldReceive('redirect')->once()->andReturnUsing(function () {
        request()->session()->put('state', 'new-oauth-state');

        return redirect('https://provider.example/authorize');
    });
    Socialite::shouldReceive('driver')->once()->with($provider)->andReturn($driver);
    $this->get(route($provider.'.redirect', ['link' => $token]))->assertRedirect('https://provider.example/authorize')
        ->assertSessionHas('social_link.oauth', ['token' => $token, 'provider' => $provider, 'state' => 'new-oauth-state']);
})->with(['google', 'discord', 'xivauth']);

it('rejects a previously proven social identity if unlinked before email verification finishes', function () {
    $user = User::factory()->unverified()->create();
    $account = $user->socialAccounts()->create(['provider' => 'discord', 'provider_user_id' => 'existing-identity']);
    $token = startSocialHandoff($user);
    fakeHandoffProvider('discord', $user->email, 'existing-identity');
    $this->withSession(secondaryHandoffContext($token))->get(route('discord.callback', ['state' => 'verified-state']))
        ->assertRedirect(route('verification.notice'));
    expect($user->fresh()->email_verified_at)->toBeNull();
    $account->delete();
    $user->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($user)->post(route('social-link.complete', $token))->assertSessionHasErrors('login');
    expect(SocialAccount::count())->toBe(0);
});

it('shows the expired auth page when a secondary callback arrives too late', function () {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'discord', 'provider_user_id' => 'existing-identity']);
    $token = startSocialHandoff($user);
    $this->travel(11)->minutes();
    fakeHandoffProvider('discord', $user->email, 'existing-identity');
    $this->withSession(secondaryHandoffContext($token))->get(route('discord.callback', ['state' => 'verified-state']))
        ->assertRedirect(route('social-link.show', $token));
    $this->assertGuest();
    expect(SocialAccount::count())->toBe(1);
});
